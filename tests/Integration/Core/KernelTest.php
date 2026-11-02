<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Core;

use Concursei\Tests\Support\IntegrationTestCase;

final class KernelTest extends IntegrationTestCase
{
    public function testHomeRendersWithSecurityHeadersAndPublicCache(): void
    {
        $res = $this->request('GET', '/');
        self::assertSame(200, $res->status());
        self::assertStringContainsString('<html lang="pt-BR">', $res->body());
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) $res->getHeader('X-Request-Id'));
        self::assertStringContainsString("default-src 'self'", (string) $res->getHeader('Content-Security-Policy'));
        self::assertSame('DENY', $res->getHeader('X-Frame-Options'));
        self::assertSame('public, max-age=300', $res->getHeader('Cache-Control'), 'Visitante anônimo sem sessão pode receber cache público');
    }

    public function testNonceInCspMatchesScriptTag(): void
    {
        $res = $this->request('GET', '/');
        preg_match("/'nonce-([^']+)'/", (string) $res->getHeader('Content-Security-Policy'), $m);
        self::assertNotEmpty($m[1]);
        self::assertStringContainsString('nonce="' . $m[1] . '"', $res->body());
    }

    public function testNotFoundHtmlAndJsonWithRequestId(): void
    {
        $html = $this->request('GET', '/nao-existe');
        self::assertSame(404, $html->status());
        self::assertStringContainsString('Página não encontrada', $html->body());
        self::assertStringContainsString((string) $html->getHeader('X-Request-Id'), $html->body());
        self::assertSame('private, no-store', $html->getHeader('Cache-Control'));

        $json = $this->request('GET', '/nao-existe', [], ['HTTP_ACCEPT' => 'application/json']);
        $body = json_decode($json->body(), true);
        self::assertSame('not_found', $body['error']['code']);
        self::assertSame($json->getHeader('X-Request-Id'), $body['error']['request_id']);
    }

    public function testViewStateDoesNotLeakBetweenRequests(): void
    {
        $view = $this->app->container->get(\Concursei\Core\View\View::class);
        $view->share('noindex', true);
        $view->share('title', 'Dado da requisição anterior');
        $body = $this->request('GET', '/')->body();
        self::assertStringNotContainsString('noindex', $body);
        self::assertStringNotContainsString('Dado da requisição anterior', $body);
    }

    public function testHealthEndpoint(): void
    {
        $res = $this->request('GET', '/saude');
        self::assertSame(200, $res->status());
        self::assertSame(['status' => 'ok'], json_decode($res->body(), true));
    }

    public function testErrorsDoNotLeakInternals(): void
    {
        $res = $this->request('GET', '/../../etc/passwd');
        self::assertContains($res->status(), [404]);
        self::assertStringNotContainsString('root:', $res->body());
        self::assertStringNotContainsString('Stack trace', $res->body());
    }
}
