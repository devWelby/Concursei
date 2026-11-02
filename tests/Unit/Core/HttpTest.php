<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Core;

use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\IpUtils;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Http\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function testRouterMatchesParamsWithConstraints(): void
    {
        $r = new Router();
        $r->get('/concursos/{slug:[a-z0-9-]+}', ['X', 'show'], ['name' => 'contest']);
        $r->post('/tentativas/{id:\d+}/concluir', ['X', 'finish']);
        [$route, $params] = $r->match('GET', '/concursos/banco-do-brasil-2026');
        self::assertSame('banco-do-brasil-2026', $params['slug']);
        self::assertSame('/concursos/abc?x=1', $r->url('contest', ['slug' => 'abc'], ['x' => 1]));
        [, $p2] = $r->match('POST', '/tentativas/15/concluir');
        self::assertSame('15', $p2['id']);
    }

    public function testRouterRejectsUnknownAndWrongMethod(): void
    {
        $r = new Router();
        $r->post('/x', ['X', 'y']);
        try {
            $r->match('GET', '/x');
            self::fail('Esperava 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
            self::assertSame('POST', $e->headers['Allow']);
        }
        $this->expectException(HttpException::class);
        $r->match('GET', '/nao-existe');
    }

    public function testRouteConstraintRejectsTraversalLikeSlug(): void
    {
        $r = new Router();
        $r->get('/concursos/{slug:[a-z0-9-]+}', ['X', 'show']);
        $this->expectException(HttpException::class);
        $r->match('GET', Request::normalizePath('/concursos/..%2F..%2Fetc'));
    }

    /** @return iterable<string,array{string,string}> */
    public static function redirectProvider(): iterable
    {
        yield 'local' => ['/painel', '/painel'];
        yield 'absoluto externo' => ['https://evil.example/x', '/'];
        yield 'protocol-relative' => ['//evil.example', '/'];
        yield 'barra invertida' => ['/\\evil.example', '/'];
        yield 'crlf' => ["/ok\r\nSet-Cookie: x=1", '/'];
        yield 'vazio' => ['', '/'];
    }

    #[DataProvider('redirectProvider')]
    public function testRedirectOnlyToLocalPaths(string $target, string $expected): void
    {
        self::assertSame($expected, Response::redirect($target)->getHeader('Location'));
    }

    public function testHeaderInjectionIsStripped(): void
    {
        $res = (new Response())->header('X-Test', "a\r\nSet-Cookie: x=1");
        self::assertSame('aSet-Cookie: x=1', $res->getHeader('X-Test'));
    }

    public function testClientIpIgnoresForwardedForUnlessProxyTrusted(): void
    {
        $req = new Request('GET', '/', server: ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
        self::assertSame('198.51.100.7', $req->clientIp([]));
        self::assertSame('198.51.100.7', $req->clientIp(['10.0.0.0/8']));
        $viaProxy = new Request('GET', '/', server: ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 203.0.113.9, 10.0.0.4']);
        self::assertSame('203.0.113.9', $viaProxy->clientIp(['10.0.0.0/8']));
    }

    public function testIpCidrMatchingV4AndV6(): void
    {
        self::assertTrue(IpUtils::inCidr('192.168.1.10', '192.168.0.0/16'));
        self::assertFalse(IpUtils::inCidr('192.169.1.10', '192.168.0.0/16'));
        self::assertTrue(IpUtils::inCidr('fd12:3456::1', 'fc00::/7'));
        self::assertFalse(IpUtils::inCidr('2001:db8::1', 'fc00::/7'));
        self::assertFalse(IpUtils::inCidr('not-an-ip', '0.0.0.0/0'));
        self::assertFalse(IpUtils::inCidr('::1', '0.0.0.0/0'), 'Famílias diferentes não casam');
    }

    public function testIntParamRejectsNonNumeric(): void
    {
        $req = (new Request('GET', '/'))->withRouteParams(['id' => '1 OR 1=1']);
        $this->expectException(HttpException::class);
        $req->intParam('id');
    }

    public function testInvalidJsonBodyIs400(): void
    {
        $req = new Request('POST', '/api/x', server: ['CONTENT_TYPE' => 'application/json'], rawBody: '{invalid');
        try {
            $req->json();
            self::fail('Esperava exceção');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status);
        }
    }

    public function testOnlyReturnsAllowlistedFields(): void
    {
        $req = new Request('POST', '/x', post: ['name' => 'a', 'role' => 'admin']);
        self::assertSame(['name' => 'a'], $req->only(['name']));
    }
}
