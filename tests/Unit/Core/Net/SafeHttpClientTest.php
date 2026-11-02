<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Core\Net;

use Concursei\Core\Config;
use Concursei\Core\Net\FetchFailed;
use Concursei\Core\Net\HttpTransport;
use Concursei\Core\Net\PinnedRequest;
use Concursei\Core\Net\RawResponse;
use Concursei\Core\Net\Resolver;
use Concursei\Core\Net\SafeHttpClient;
use Concursei\Core\Net\SafeUrl;
use Concursei\Core\Net\UnsafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeHttpClientTest extends TestCase
{
    private const ALLOWED = ['www.orgao.gov.br', '*.banca.org.br'];

    /** @var array<string,list<string>> */
    private array $dns = [
        'www.orgao.gov.br' => ['200.160.2.3'],
        'banca.org.br' => ['2804:14c::1', '177.10.20.30'],
        'cdn.banca.org.br' => ['177.10.20.31'],
        'interno.banca.org.br' => ['177.10.20.32', '10.0.0.5'],
        'metadata.banca.org.br' => ['169.254.169.254'],
    ];

    /** @var list<RawResponse> */
    private array $responses = [];
    /** @var list<PinnedRequest> */
    private array $sent = [];

    private function client(array $config = []): SafeHttpClient
    {
        $resolver = new class ($this->dns) implements Resolver {
            public function __construct(private array $dns)
            {
            }

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        };
        $transport = new class ($this) implements HttpTransport {
            public function __construct(private SafeHttpClientTest $test)
            {
            }

            public function send(PinnedRequest $request): RawResponse
            {
                return $this->test->next($request);
            }
        };
        return new SafeHttpClient($resolver, $transport, new Config(['collector' => $config + ['max_redirects' => 3, 'allowed_ports' => [443]]]));
    }

    public function next(PinnedRequest $request): RawResponse
    {
        $this->sent[] = $request;
        return array_shift($this->responses) ?? throw new \LogicException('Sem resposta preparada');
    }

    private function respond(int $status, array $headers = [], string $body = ''): void
    {
        $this->responses[] = new RawResponse($status, $headers, $body, '200.160.2.3');
    }

    /** @return iterable<string,array{string}> */
    public static function rejectedUrls(): iterable
    {
        yield 'http' => ['http://www.orgao.gov.br/edital.pdf'];
        yield 'credenciais' => ['https://user:senha@www.orgao.gov.br/']; // repo-safety: allow (credencial fictícia: o teste prova a recusa)
        yield 'porta' => ['https://www.orgao.gov.br:8443/'];
        yield 'ip literal' => ['https://200.160.2.3/'];
        yield 'ipv6 literal' => ['https://[::1]/'];
        yield 'decimal' => ['https://2130706433/'];
        yield 'hex' => ['https://0x7f.1/'];
        yield 'fora da allowlist' => ['https://www.outro.gov.br/'];
        yield 'sufixo enganoso' => ['https://www.orgao.gov.br.evil.example/'];
        yield 'prefixo enganoso' => ['https://evilbanca.org.br/'];
        yield 'barra invertida' => ['https://www.orgao.gov.br\\@evil.example/'];
        yield 'espaço' => ['https://www.orgao.gov.br/a b'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://www.orgao.gov.br/'];
    }

    #[DataProvider('rejectedUrls')]
    public function testPolicyRejectsBeforeAnyConnection(string $url): void
    {
        try {
            $this->client()->get($url, self::ALLOWED);
            self::fail('Deveria recusar ' . $url);
        } catch (UnsafeUrl) {
            self::assertSame([], $this->sent, 'Nenhuma conexão antes da validação');
        }
    }

    public function testPinsValidatedAddressAndPrefersIpv4(): void
    {
        $this->respond(200, ['content-type' => 'application/pdf', 'etag' => '"v1"'], '%PDF-1.7');
        $result = $this->client()->get('https://banca.org.br/edital.pdf#p2', self::ALLOWED, ['etag' => '"v0"']);
        self::assertSame('https://banca.org.br/edital.pdf', $result->url, 'Fragmento descartado');
        self::assertSame('application/pdf', $result->mimeType);
        self::assertSame('"v1"', $result->etag);
        self::assertSame('177.10.20.30', $this->sent[0]->ip);
        self::assertSame('"v0"', $this->sent[0]->headers['If-None-Match']);
    }

    public function testAnyPrivateAddressInDnsAnswerBlocks(): void
    {
        foreach (['https://interno.banca.org.br/', 'https://metadata.banca.org.br/'] as $url) {
            try {
                $this->client()->get($url, self::ALLOWED);
                self::fail('DNS com endereço interno deve bloquear');
            } catch (UnsafeUrl $e) {
                self::assertStringContainsString('não público', $e->getMessage());
            }
        }
        self::assertSame([], $this->sent);
    }

    public function testEveryRedirectHopIsRevalidated(): void
    {
        $this->respond(302, ['location' => '/novo/edital.pdf']);
        $this->respond(301, ['location' => 'https://cdn.banca.org.br/arquivo.pdf']);
        $this->respond(200, ['content-type' => 'application/pdf; charset=binary'], '%PDF');
        $result = $this->client()->get('https://www.orgao.gov.br/antigo', self::ALLOWED);
        self::assertSame('https://cdn.banca.org.br/arquivo.pdf', $result->url);
        self::assertSame(['https://www.orgao.gov.br/antigo', 'https://www.orgao.gov.br/novo/edital.pdf'], $result->redirects);
        self::assertSame('177.10.20.31', $this->sent[2]->ip);

        foreach (['https://interno.banca.org.br/x', 'http://www.orgao.gov.br/x', 'https://www.outro.gov.br/', 'https://127.0.0.1/'] as $evil) {
            $this->sent = [];
            $this->respond(302, ['location' => $evil]);
            try {
                $this->client()->get('https://www.orgao.gov.br/', self::ALLOWED);
                self::fail('Redirecionamento para ' . $evil . ' deve ser recusado');
            } catch (UnsafeUrl) {
                self::assertCount(1, $this->sent, 'Destino do redirecionamento não recebe conexão');
            }
        }
    }

    public function testRedirectLimitStatusAndTypeHandling(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->respond(302, ['location' => '/volta' . $i]);
        }
        try {
            $this->client()->get('https://www.orgao.gov.br/', self::ALLOWED);
            self::fail('Limite de redirecionamentos');
        } catch (UnsafeUrl $e) {
            self::assertStringContainsString('demais', $e->getMessage());
        }

        $this->responses = [];
        $this->respond(304, ['etag' => '"v1"']);
        self::assertTrue($this->client()->get('https://www.orgao.gov.br/', self::ALLOWED)->notModified());

        $this->respond(429, ['retry-after' => '120']);
        try {
            $this->client()->get('https://www.orgao.gov.br/', self::ALLOWED);
            self::fail('429');
        } catch (FetchFailed $e) {
            self::assertTrue($e->retryable);
            self::assertSame(120, $e->retryAfterSeconds);
        }

        $this->respond(404);
        try {
            $this->client()->get('https://www.orgao.gov.br/', self::ALLOWED);
            self::fail('404');
        } catch (FetchFailed $e) {
            self::assertFalse($e->retryable);
        }

        $this->respond(200, ['content-type' => 'application/x-msdownload'], 'MZ');
        $this->expectException(FetchFailed::class);
        $this->client()->get('https://www.orgao.gov.br/', self::ALLOWED);
    }

    public function testRelativeRedirectResolution(): void
    {
        self::assertSame('https://a.gov.br/x/c.pdf', SafeUrl::resolve('https://a.gov.br/x/y/z', '../c.pdf'));
        self::assertSame('https://a.gov.br/x/y/c.pdf?v=2', SafeUrl::resolve('https://a.gov.br/x/y/z', 'c.pdf?v=2'));
        self::assertSame('https://a.gov.br/raiz', SafeUrl::resolve('https://a.gov.br/x/y', '/raiz'));
        self::assertSame('https://b.gov.br/p', SafeUrl::resolve('https://a.gov.br/x', '//b.gov.br/p'));
        self::assertSame('https://a.gov.br/x?q=1', SafeUrl::resolve('https://a.gov.br/x', '?q=1'));
        self::assertSame('http://b.gov.br/', SafeUrl::resolve('https://a.gov.br/x', 'http://b.gov.br/'));
        self::assertTrue(SafeUrl::hostAllowed('banca.org.br', ['*.banca.org.br']));
        self::assertFalse(SafeUrl::hostAllowed('xbanca.org.br', ['*.banca.org.br']));
    }
}
