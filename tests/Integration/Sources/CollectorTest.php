<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Sources;

use Concursei\Core\Net\FetchFailed;
use Concursei\Core\Net\HttpTransport;
use Concursei\Core\Net\Resolver;
use Concursei\Modules\Jobs\Queue;
use Concursei\Modules\Jobs\Worker;
use Concursei\Modules\Sources\DocumentStore;
use Concursei\Modules\Sources\SourceRepository;
use Concursei\Tests\Support\FakeNetwork;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;
use Concursei\Tests\Support\InteractsWithCatalog;
use Concursei\Tests\Support\PdfFixture;
use DateTimeImmutable;

final class CollectorTest extends IntegrationTestCase
{
    use InteractsWithAuth;
    use InteractsWithCatalog;

    private const PAGE = 'https://www.orgao.gov.br/concursos/2027';
    private FakeNetwork $net;
    private ?int $editorId = null;
    private string $storeDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:00:00'));
        $this->net = new FakeNetwork();
        $this->storeDir = sys_get_temp_dir() . '/concursei-docs-' . bin2hex(random_bytes(4));
        $c = $this->app->container;
        $c->set(Resolver::class, $this->net);
        $c->set(HttpTransport::class, $this->net);
        $c->set(DocumentStore::class, new DocumentStore($this->storeDir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*/*') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->storeDir . '/*') ?: [] as $d) {
            rmdir($d);
        }
        @rmdir($this->storeDir);
        parent::tearDown();
    }

    private function source(array $data = []): int
    {
        return $this->app->container->get(SourceRepository::class)->create($data + [
            'name' => 'Página do concurso', 'kind' => 'official', 'method' => 'page', 'url' => self::PAGE,
            'extra_hosts' => 'arquivos.orgao.gov.br', 'check_every_hours' => 24, 'enabled' => 1,
        ], $this->editorId ??= $this->createUser('equipe@example.com', 'editor'));
    }

    private function runCron(): void
    {
        $repo = $this->app->container->get(SourceRepository::class);
        foreach ($repo->claimDue(20) as $id) {
            $this->app->container->get(Queue::class)->dispatch('source.fetch', ['source_id' => $id], 'source.fetch:' . $id);
        }
        $this->app->container->get(Worker::class)->run(50, 60);
    }

    public function testPageDiscoveryDownloadAndTextExtraction(): void
    {
        $sourceId = $this->source();
        $this->net->on(self::PAGE, 200, '<html><body><h1>Concurso 2027</h1>'
            . '<a href="/arquivos/edital-1.pdf">Edital</a> <a href="https://arquivos.orgao.gov.br/retificacao-1.pdf">Retificação</a>'
            . '<a href="https://www.outro.gov.br/falso.pdf">Fora da lista</a> <a href="/noticias">Notícias</a>'
            . '<a href="http://www.orgao.gov.br/inseguro.pdf">HTTP</a></body></html>', ['Content-Type' => 'text/html; charset=utf-8', 'ETag' => '"p1"']);
        $edital = PdfFixture::make([['EDITAL DE ABERTURA No 1/2027', 'Inscricoes de 10/03/2027 a 09/04/2027 pelo site da banca organizadora do certame.']]);
        $this->net->on('https://www.orgao.gov.br/arquivos/edital-1.pdf', 200, $edital, ['Content-Type' => 'application/pdf']);
        $this->net->on('https://arquivos.orgao.gov.br/retificacao-1.pdf', 200, PdfFixture::make([[]]), ['Content-Type' => 'application/pdf']);

        $this->runCron();

        self::assertSame([self::PAGE, 'https://www.orgao.gov.br/arquivos/edital-1.pdf', 'https://arquivos.orgao.gov.br/retificacao-1.pdf'], $this->net->urls(), 'Só PDFs HTTPS em hosts autorizados');
        $docs = $this->db->all('SELECT url, sha256, text_status, storage_path, page_count FROM source_documents ORDER BY id');
        self::assertCount(2, $docs);
        self::assertSame(['ready', 'empty'], array_column($docs, 'text_status'));
        self::assertSame(hash('sha256', $edital), $docs[0]['sha256']);
        self::assertSame($edital, file_get_contents($this->storeDir . '/' . $docs[0]['storage_path']), 'Original guardado fora do webroot, nomeado pelo hash');
        self::assertStringContainsString('Inscricoes de 10/03/2027', (string) $this->db->scalar('SELECT text FROM source_documents WHERE id = (SELECT MIN(id) FROM source_documents)'));

        $source = $this->db->one('SELECT * FROM sources WHERE id = ?', [$sourceId]);
        self::assertSame(['ok', '"p1"', '2027-03-11 12:00:00'], [$source['health'], $source['etag'], $source['next_check_at']]);

        // Próximo ciclo: condicional com ETag; 304 não gera trabalho novo.
        $this->clock->setNow(new DateTimeImmutable('2027-03-11 12:00:00'));
        $this->net->on(self::PAGE, 304, '', ['ETag' => '"p1"']);
        $this->net->requests = [];
        $this->runCron();
        self::assertSame('"p1"', $this->net->requests[0]->headers['If-None-Match']);
        self::assertCount(1, $this->net->requests);
        self::assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM source_documents'));
    }

    public function testFailuresUpdateHealthAndPolicyBlocks(): void
    {
        $sourceId = $this->source();
        $this->net->fail(self::PAGE, new FetchFailed('Falha de conexão ou tempo esgotado (cURL 28).', true));
        $this->runCron();
        $source = $this->db->one('SELECT health, consecutive_failures, last_error FROM sources WHERE id = ?', [$sourceId]);
        self::assertSame(['failing', '1'], [$source['health'], (string) $source['consecutive_failures']]);
        self::assertSame('retry_scheduled', $this->db->scalar("SELECT status FROM jobs WHERE type = 'source.fetch'"));

        $blocked = $this->source(['name' => 'Rebinding', 'url' => 'https://interno.orgao.gov.br/', 'method' => 'document']);
        $this->net->dns['interno.orgao.gov.br'] = ['200.160.2.4', '192.168.0.10'];
        $this->runCron();
        $source = $this->db->one('SELECT health, last_error FROM sources WHERE id = ?', [$blocked]);
        self::assertSame('blocked', $source['health']);
        self::assertStringContainsString('não público', $source['last_error']);
        self::assertNotContains('https://interno.orgao.gov.br/', $this->net->urls(), 'Nenhuma conexão para destino interno');
        self::assertSame('failed', $this->db->scalar("SELECT status FROM jobs WHERE payload LIKE ? ", ['%"source_id":' . $blocked . '%']));
    }

    public function testAdminRegistersSourcesWithinPolicy(): void
    {
        $this->loginAs($this->createUser('aluna@example.com'));
        self::assertSame(403, $this->request('GET', '/admin/fontes')->status());
        $this->session()->invalidate();

        $editor = $this->staff();
        $this->loginAs($editor->id);
        $valid = ['name' => 'Diário oficial', 'kind' => 'official', 'method' => 'document', 'url' => 'https://www.orgao.gov.br/edital.pdf', 'check_every_hours' => '12', 'enabled' => '1'];
        foreach ([
            ['url' => 'http://www.orgao.gov.br/edital.pdf'],
            ['url' => 'https://10.0.0.1/edital.pdf'],
            ['url' => 'https://www.orgao.gov.br:8080/edital.pdf'],
            ['extra_hosts' => 'cdn.orgao.gov.br, 169.254.169.254'],
            ['check_every_hours' => '0'],
        ] as $bad) {
            $this->assertRedirect($this->post('/admin/fontes', $bad + $valid), '/admin/fontes/nova');
        }
        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM sources'));

        $res = $this->post('/admin/fontes', ['extra_hosts' => 'CDN.orgao.gov.br, *.banca.org.br'] + $valid);
        $id = (int) $this->db->scalar('SELECT id FROM sources');
        $this->assertRedirect($res, '/admin/fontes/' . $id);
        $row = $this->db->one('SELECT url, extra_hosts, enabled, created_by FROM sources');
        self::assertSame(['https://www.orgao.gov.br/edital.pdf', 'cdn.orgao.gov.br, *.banca.org.br', 1, $editor->id], array_values($row));
        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'source.create'"));

        $this->assertRedirect($this->post('/admin/fontes/' . $id . '/verificar'), '/admin/fontes/' . $id);
        self::assertSame('pending', $this->db->scalar("SELECT status FROM jobs WHERE type = 'source.fetch'"));

        // Documento com conteúdo hostil é exibido escapado e baixado como anexo isolado.
        $this->net->on('https://www.orgao.gov.br/edital.pdf', 200, '<p>Edital</p><script>alert(1)</script><img src=x onerror=alert(2)>', ['Content-Type' => 'text/html']);
        $this->app->container->get(Worker::class)->run(10, 30);
        $docId = (int) $this->db->scalar('SELECT id FROM source_documents');
        $page = $this->request('GET', '/admin/documentos/' . $docId);
        self::assertSame(200, $page->status());
        self::assertStringNotContainsString('<img src=x', $page->body());
        $file = $this->request('GET', '/admin/documentos/' . $docId . '/arquivo');
        self::assertSame('application/octet-stream', $file->getHeader('Content-Type'));
        self::assertStringStartsWith('attachment;', (string) $file->getHeader('Content-Disposition'));
        self::assertStringStartsWith('sandbox; default-src', (string) $file->getHeader('Content-Security-Policy'));
        self::assertSame(404, $this->request('GET', '/admin/documentos/999')->status());
    }
}
