<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Catalog;

use Concursei\Shared\Auth\AuthUser;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;
use Concursei\Tests\Support\InteractsWithCatalog;
use DateTimeImmutable;

final class PublicCatalogTest extends IntegrationTestCase
{
    use InteractsWithAuth;
    use InteractsWithCatalog;

    private AuthUser $editor;

    protected function setUp(): void
    {
        parent::setUp();
        // 10/03/2027: inscrições do concurso padrão (01–31/03) abertas.
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:00:00'));
        $this->editor = $this->staff();
    }

    public function testListingShowsOnlyPublishedContestsAndIsPubliclyCacheable(): void
    {
        $this->publishedContest($this->editor, 'publicado-sp');
        $this->contest($this->editor->id, ['title' => 'Rascunho secreto'], 'rascunho');

        $res = $this->request('GET', '/concursos');
        self::assertSame(200, $res->status());
        self::assertSame('public, max-age=300', $res->getHeader('Cache-Control'), 'Visitante sem sessão recebe página cacheável');
        self::assertStringContainsString('/concursos/publicado-sp', $res->body());
        self::assertStringContainsString('Inscrições abertas', $res->body());
        self::assertStringContainsString('em 21 dias', $res->body());
        self::assertStringNotContainsString('Rascunho secreto', $res->body());
        self::assertSame(404, $this->request('GET', '/concursos/rascunho')->status());
    }

    public function testFiltersNarrowResultsAndIgnoreInvalidValues(): void
    {
        $this->publishedContest($this->editor, 'tribunal-sp');
        $rjOrg = $this->organization('policia-rj', 'RJ');
        $rj = $this->contest($this->editor->id, ['organization_id' => $rjOrg, 'title' => 'Polícia Exemplo RJ', 'uf' => 'RJ', 'status' => 'authorized'], 'policia-rj');
        $this->offer($rj, $this->editor->id, ['title' => 'Soldado', 'education_level' => 'medio', 'career_id' => $this->career('soldado', 'policial', 'medio'), 'vacancies' => 500, 'reserve' => 0, 'salary_cents' => 450000]);
        $this->publish($rj, $this->editor);
        $national = $this->organization('instituto-nacional', null);
        $this->publishedContest($this->editor, 'nacional', ['organization_id' => $national, 'uf' => null, 'title' => 'Instituto Nacional Exemplo']);

        $slugs = fn (string $query): array => $this->slugsIn($this->request('GET', '/concursos' . $query)->body());
        self::assertSame(['nacional', 'policia-rj', 'tribunal-sp'], $this->sorted($slugs('')));
        self::assertSame(['tribunal-sp'], $slugs('?uf=SP'));
        self::assertSame(['nacional'], $slugs('?uf=nacional'));
        self::assertSame(['policia-rj', 'tribunal-sp'], $this->sorted($slugs('?regiao=sudeste')));
        self::assertSame(['policia-rj'], $slugs('?area=policial'));
        self::assertSame(['policia-rj'], $slugs('?escolaridade=medio'));
        self::assertSame(['policia-rj'], $slugs('?orgao=policia-rj'));
        self::assertSame(['policia-rj'], $slugs('?situacao=previstos'));
        self::assertSame(['nacional', 'tribunal-sp'], $this->sorted($slugs('?situacao=abertos')));
        self::assertSame(['policia-rj'], $slugs('?q=soldado'), 'Busca alcança o nome do cargo');
        self::assertSame(['policia-rj', 'nacional', 'tribunal-sp'], $slugs('?ordenar=vagas'), 'Mais vagas primeiro; empate pelo mais recente');
        self::assertSame(['nacional', 'policia-rj', 'tribunal-sp'], $this->sorted($slugs('?uf=XX&area=%27%20OR%201%3D1&ordenar=id%3B')), 'Valores inválidos são ignorados');
        self::assertSame([], $slugs('?q=' . rawurlencode("%' OR 1=1 -- ")));

        $res = $this->request('GET', '/concursos?q=soldado');
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $res->body(), 'Busca livre não é indexada');
        self::assertSame(404, $this->request('GET', '/concursos?pagina=50')->status());
    }

    public function testConcludedAndCancelledAreHiddenUnlessAsked(): void
    {
        $this->publishedContest($this->editor, 'encerrado', ['status' => 'concluded']);
        self::assertSame([], $this->slugsIn($this->request('GET', '/concursos')->body()));
        self::assertSame(['encerrado'], $this->slugsIn($this->request('GET', '/concursos?situacao=encerrados')->body()));
    }

    public function testDetailIsBuiltFromPublishedRevisionNotTheWorkingCopy(): void
    {
        $id = $this->publishedContest($this->editor, 'detalhe', ['summary' => "Primeiro parágrafo.\n\n<script>alert(1)</script>"]);
        $this->event($id, $this->editor->id, 'result', '2027-08-01', 'month');
        $this->db->run('UPDATE contests SET title = ? WHERE id = ?', ['Título ainda não revisado', $id]);

        $res = $this->request('GET', '/concursos/detalhe');
        self::assertSame(200, $res->status());
        $body = $res->body();
        self::assertStringContainsString('Concurso Exemplo 2027', $body);
        self::assertStringNotContainsString('Título ainda não revisado', $body);
        self::assertStringNotContainsString('agosto de 2027', $body, 'Data adicionada na cópia de trabalho não aparece antes de publicar');
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringContainsString('10/05/2027 às 13h', $body);
        self::assertStringContainsString('10 vagas + cadastro de reserva', $body);
        self::assertStringContainsString('R$ 13.500,00', $body);
        self::assertStringContainsString('href="https://example.com/edital.pdf" rel="nofollow noopener noreferrer"', $body);
        self::assertStringContainsString('conferidas pela equipe com o documento oficial em 10/03/2027', $body);
        self::assertStringContainsString('Publicação inicial conferida com o edital', $body);
        self::assertStringContainsString('<link rel="canonical" href="http://concursei.test/concursos/detalhe">', $body);
        self::assertStringContainsString('href="/concursos/detalhe/acompanhar"', $body);

        $this->publication()->submit($id, $this->version($id), $this->editor);
        $this->publication()->publish($id, $this->version($id), $this->editor, 'Resultado previsto para agosto', false, true);
        self::assertStringContainsString('agosto de 2027 (previsto)', $this->request('GET', '/concursos/detalhe')->body());

        $this->publication()->archive($id, $this->editor);
        self::assertSame(404, $this->request('GET', '/concursos/detalhe')->status());
        self::assertSame(404, $this->request('GET', '/concursos/Detalhe')->status());
        self::assertSame(404, $this->request('GET', '/concursos/' . str_repeat('a', 200))->status());
    }

    public function testCalendarSeparatesExactDaysFromMonthOnlyDates(): void
    {
        $id = $this->publishedContest($this->editor, 'calendario');
        $this->event($id, $this->editor->id, 'answer_key', '2027-03-01', 'month');
        $this->event($id, $this->editor->id, 'result', null, 'unknown');
        $this->publication()->submit($id, $this->version($id), $this->editor);
        $this->publication()->publish($id, $this->version($id), $this->editor, 'Datas de gabarito', false, true);

        $res = $this->request('GET', '/calendario?mes=2027-03');
        self::assertSame(200, $res->status());
        $body = $res->body();
        self::assertStringContainsString('01/03/2027, segunda-feira', $body);
        self::assertStringContainsString('31/03/2027, quarta-feira', $body);
        self::assertStringContainsString('Previstos para março de 2027, sem dia definido', $body);
        self::assertStringContainsString('Gabarito: <a href="/concursos/calendario">', $body);
        self::assertStringNotContainsString('Resultado: <a', $body, 'Datas desconhecidas não entram no calendário');

        self::assertStringContainsString('10/05/2027, segunda-feira', $this->request('GET', '/calendario?mes=2027-05')->body());
        self::assertStringContainsString('Nenhuma data publicada', $this->request('GET', '/calendario?mes=2027-05&uf=RJ')->body());
        self::assertStringContainsString('Março de 2027', $this->request('GET', '/calendario?mes=2027-13')->body(), 'Mês inválido cai no mês atual');
    }

    /** @return list<string> */
    private function slugsIn(string $html): array
    {
        preg_match_all('#<h2><a href="/concursos/([a-z0-9-]+)">#', $html, $m);
        return $m[1];
    }

    /** @param list<string> $list @return list<string> */
    private function sorted(array $list): array
    {
        sort($list);
        return $list;
    }
}
