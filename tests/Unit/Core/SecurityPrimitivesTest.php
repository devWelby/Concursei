<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Core;

use Concursei\Core\Database\Migrator;
use Concursei\Core\Logging\Logger;
use Concursei\Core\Security\Crypto;
use Concursei\Core\Security\SecurityHeaders;
use Concursei\Core\Http\Response;
use PHPUnit\Framework\TestCase;

final class SecurityPrimitivesTest extends TestCase
{
    private function crypto(): Crypto
    {
        return new Crypto('base64:' . base64_encode(str_repeat("\x01", 32)));
    }

    public function testEncryptDecryptRoundTripAndTamperDetection(): void
    {
        $c = $this->crypto();
        $ct = $c->encrypt('semente-totp', 'user:1');
        self::assertSame('semente-totp', $c->decrypt($ct, 'user:1'));
        self::assertNotSame($ct, $c->encrypt('semente-totp', 'user:1'), 'IV aleatório');
        $this->expectException(\RuntimeException::class);
        $c->decrypt($ct, 'user:2'); // AAD diferente = falha
    }

    public function testTamperedCiphertextFails(): void
    {
        $c = $this->crypto();
        $bin = base64_decode($c->encrypt('x'));
        $bin[strlen($bin) - 1] = $bin[strlen($bin) - 1] === 'a' ? 'b' : 'a';
        $this->expectException(\RuntimeException::class);
        $c->decrypt(base64_encode($bin));
    }

    public function testWeakOrMissingKeyIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        new Crypto('base64:' . base64_encode('curta'));
    }

    public function testMacIsDomainSeparated(): void
    {
        $c = $this->crypto();
        self::assertSame(32, strlen($c->mac('ip', '1.2.3.4')));
        self::assertNotSame($c->mac('ip', '1.2.3.4'), $c->mac('ratelimit', '1.2.3.4'));
    }

    public function testRandomTokensAreUnique(): void
    {
        $tokens = array_map(static fn () => Crypto::randomToken(), range(1, 50));
        self::assertCount(50, array_unique($tokens));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $tokens[0]);
    }

    public function testLoggerRedactsSecretsAndEmails(): void
    {
        $ctx = Logger::redact([
            'password' => 'segredo',
            'csrf_token' => 'abc',
            'nested' => ['api_key' => 'k', 'note' => 'contato: fulano@escola.test'],
            // Chave sintética montada em tempo de execução (não é segredo real).
            'url' => 'https://x.example/?q=1&k2=' . 'AIza' . str_repeat('F', 35),
        ]);
        self::assertSame('[REDACTED]', $ctx['password']);
        self::assertSame('[REDACTED]', $ctx['csrf_token']);
        self::assertSame('[REDACTED]', $ctx['nested']['api_key']);
        self::assertStringNotContainsString('fulano@', $ctx['nested']['note']);
        self::assertStringNotContainsString('AIza', $ctx['url']);
    }

    public function testSecurityHeadersAndNonceCsp(): void
    {
        $h = new SecurityHeaders(true, true);
        $res = $h->apply(new Response());
        $csp = (string) $res->getHeader('Content-Security-Policy');
        self::assertStringContainsString("'nonce-" . $h->nonce() . "'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertSame('nosniff', $res->getHeader('X-Content-Type-Options'));
        self::assertNotNull($res->getHeader('Strict-Transport-Security'));
        $noHsts = (new SecurityHeaders(true, false))->apply(new Response());
        self::assertNull($noHsts->getHeader('Strict-Transport-Security'), 'HSTS só com HTTPS');
    }

    public function testCspExtraSourcesOnlyAcceptHttpsHosts(): void
    {
        $h = new SecurityHeaders(false, true);
        $h->allowSources(['script' => ['https://ads.example.com', '*', "https://x.example 'unsafe-inline'", 'http://insecure.example']]);
        $csp = $h->csp();
        self::assertStringContainsString('https://ads.example.com', $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('http://insecure', $csp);
        self::assertStringNotContainsString(" * ", $csp . ' ');
    }

    public function testHtmlEscapingHelpers(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        self::assertSame('#', e_url('javascript:alert(1)'));
        self::assertSame('#', e_url('data:text/html,x'));
        self::assertSame('#', e_url('//evil.example'));
        self::assertSame('/concursos?a=1&amp;b=2', e_url('/concursos?a=1&b=2'));
        self::assertStringNotContainsString('</script>', e_json(['x' => '</script><script>']));
    }

    public function testMigrationSplitter(): void
    {
        $parts = Migrator::split("-- comentário\nCREATE TABLE a (x INT);\n\nCREATE TABLE b (\n  y VARCHAR(10) COMMENT 'a;b'\n);\n");
        self::assertCount(2, $parts);
        self::assertStringContainsString("COMMENT 'a;b'", $parts[1]);
    }
}
