<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Ai;

use Concursei\Modules\Ai\AiError;
use Concursei\Modules\Ai\AiRequest;
use Concursei\Modules\Ai\GeminiProvider;
use PHPUnit\Framework\TestCase;

final class GeminiProviderTest extends TestCase
{
    private static function ok(string $text, string $finish = 'STOP', array $extra = []): string
    {
        return (string) json_encode([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'rascunho interno', 'thought' => true], ['text' => $text]]], 'finishReason' => $finish]],
            'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 300, 'thoughtsTokenCount' => 100, 'totalTokenCount' => 1600],
            'modelVersion' => 'modelo-x-001',
        ] + $extra);
    }

    private static function kind(callable $fn): string
    {
        try {
            $fn();
        } catch (AiError $e) {
            return $e->kind . ($e->retryable ? ':retry' : '') . ($e->retryAfterSeconds !== null ? ':' . $e->retryAfterSeconds : '') . ($e->uncertain ? ':incerto' : '');
        }
        return 'sem erro';
    }

    public function testParsesStructuredJsonAndUsageIgnoringThoughts(): void
    {
        $r = GeminiProvider::parse(200, self::ok('{"titulo":"Edital 1/2027","datas":[]}'), null, 'modelo-x');
        self::assertSame(['titulo' => 'Edital 1/2027', 'datas' => []], $r->data);
        self::assertSame([1200, 400, 1600, 'STOP', 'modelo-x-001'], [$r->promptTokens, $r->outputTokens, $r->totalTokens, $r->finishReason, $r->model]);

        $fenced = GeminiProvider::parse(200, self::ok("```json\n{\"a\":1}\n```"), null, 'm');
        self::assertSame(['a' => 1], $fenced->data);
    }

    public function testUnusableAnswersBecomeTypedFailures(): void
    {
        self::assertSame('blocked', self::kind(fn () => GeminiProvider::parse(200, (string) json_encode(['promptFeedback' => ['blockReason' => 'SAFETY']]), null, 'm')));
        self::assertSame('blocked', self::kind(fn () => GeminiProvider::parse(200, self::ok('{}', 'SAFETY'), null, 'm')));
        self::assertSame('truncated', self::kind(fn () => GeminiProvider::parse(200, self::ok('{"a":', 'MAX_TOKENS'), null, 'm')));
        self::assertSame('empty', self::kind(fn () => GeminiProvider::parse(200, self::ok('  '), null, 'm')));
        self::assertSame('invalid_json', self::kind(fn () => GeminiProvider::parse(200, self::ok('não é json'), null, 'm')));
        self::assertSame('invalid_json', self::kind(fn () => GeminiProvider::parse(200, self::ok('[1,2]'), null, 'm')), 'Lista no lugar de objeto');
        self::assertSame('invalid_json', self::kind(fn () => GeminiProvider::parse(200, '<html>proxy</html>', null, 'm')));
    }

    public function testHttpErrorsMapToRetryPolicy(): void
    {
        $quota = (string) json_encode(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '37s']]]]);
        self::assertSame('rate_limited:retry:37', self::kind(fn () => GeminiProvider::parse(429, $quota, null, 'm')));
        self::assertSame('rate_limited:retry:20', self::kind(fn () => GeminiProvider::parse(429, '{}', '20', 'm')));
        self::assertSame('unavailable:retry', self::kind(fn () => GeminiProvider::parse(503, '{"error":{"status":"UNAVAILABLE"}}', null, 'm')));
        self::assertSame('timeout:retry:incerto', self::kind(fn () => GeminiProvider::parse(504, '', null, 'm')));
        self::assertSame('auth', self::kind(fn () => GeminiProvider::parse(403, '{"error":{"status":"PERMISSION_DENIED"}}', null, 'm')));
        self::assertSame('bad_request', self::kind(fn () => GeminiProvider::parse(404, '{"error":{"status":"NOT_FOUND"}}', null, 'm')));
    }

    public function testRequiresKeyAndPlausibleModelWithoutTouchingNetwork(): void
    {
        $request = new AiRequest('teste', 'v1', 'sistema', 'texto', ['type' => 'OBJECT']);
        self::assertSame('config', self::kind(fn () => (new GeminiProvider('', 'modelo-estavel', 'https://example.invalid', 5))->generate($request)));
        self::assertFalse((new GeminiProvider('chave-de-teste', '', 'https://example.invalid', 5))->isConfigured());
        self::assertFalse((new GeminiProvider('chave-de-teste', '../outro?x=1', 'https://example.invalid', 5))->isConfigured());
        self::assertTrue((new GeminiProvider('chave-de-teste', 'modelo-estavel-2.5', 'https://example.invalid', 5))->isConfigured());
    }
}
