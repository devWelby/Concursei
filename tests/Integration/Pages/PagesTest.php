<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Pages;

use Concursei\Core\Config;
use Concursei\Tests\Support\IntegrationTestCase;
use Concursei\Tests\Support\InteractsWithAuth;
use Concursei\Tests\Support\InteractsWithCatalog;
use DateTimeImmutable;

final class PagesTest extends IntegrationTestCase
{
    use InteractsWithAuth;
    use InteractsWithCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->setNow(new DateTimeImmutable('2027-02-15 12:00:00'));
    }

    public function testFooterAndSignupLinksResolveToCacheablePages(): void
    {
        $home = $this->request('GET', '/')->body();
        foreach (['/sobre' => 'Sobre o Concursei e metodologia', '/correcoes' => 'Correções', '/privacidade' => 'Política de privacidade', '/termos' => 'Termos de uso', '/cookies' => 'Cookies'] as $path => $heading) {
            self::assertStringContainsString('href="' . $path . '"', $home, "Rodapé aponta para {$path}");
            $res = $this->request('GET', $path);
            self::assertSame(200, $res->status(), $path);
            self::assertStringContainsString('<h1>' . $heading, $res->body(), $path);
            self::assertSame('public, max-age=300', $res->getHeader('Cache-Control'), $path);
        }
        preg_match_all('/href="(\/[^"#?]*)"/', $home, $links);
        foreach (array_unique($links[1]) as $path) {
            $status = $this->request('GET', $path)->status();
            self::assertNotSame(404, $status, "Link interno da página inicial quebrado: {$path}");
        }
        $signup = $this->request('GET', '/cadastro')->body();
        self::assertStringContainsString('href="/termos"', $signup);
        self::assertStringContainsString('href="/privacidade"', $signup);
    }

    public function testWithoutOperatorConfiguredNothingIsInvented(): void
    {
        $missing = $this->request('GET', '/privacidade')->body();
        self::assertStringContainsString('pendente de configuração', $missing, 'Sem OPERATOR_NAME o site não inventa responsável');
        self::assertStringContainsString('<dt>Responsável</dt><dd>a definir</dd>', $missing);
    }

    public function testOperatorIdentityComesFromConfiguration(): void
    {
        $c = $this->app->container;
        $c->set(Config::class, $c->get(Config::class)->with('app.operator_name', 'Responsável Exemplo')->with('app.contact_email', 'contato@example.com'));
        $privacy = $this->request('GET', '/privacidade')->body();
        self::assertStringNotContainsString('pendente de configuração', $privacy);
        self::assertStringContainsString('Responsável Exemplo', $privacy);
        self::assertStringContainsString('mailto:contato@example.com', $privacy);
        self::assertStringContainsString('Versão ' . $c->get(Config::class)->string('legal.privacy_version'), $privacy, 'Mostra a versão aceita no cadastro');
        self::assertStringContainsString('concursei_session', $this->request('GET', '/cookies')->body(), 'Nome real do cookie neste ambiente');
    }

    public function testCorrectionsListOnlyPublishedCorrectionsOfVisibleContests(): void
    {
        $editor = $this->staff();
        $id = $this->publishedContest($editor);
        $this->event($id, $editor->id, 'answer_key', '2027-05-12');
        $this->publication()->submit($id, $this->version($id), $editor);
        $this->publication()->publish($id, $this->version($id), $editor, 'Data do gabarito incluída', false, true);
        self::assertStringContainsString('Nenhuma correção publicada', $this->request('GET', '/correcoes')->body(), 'Atualização comum não é correção');

        $this->event($id, $editor->id, 'result', '2027-06-30');
        $this->publication()->submit($id, $this->version($id), $editor);
        $this->publication()->publish($id, $this->version($id), $editor, 'Data do resultado corrigida conforme retificação <b>nº 2</b>', true, true);
        $html = $this->request('GET', '/correcoes')->body();
        self::assertStringContainsString('Data do resultado corrigida conforme retificação &lt;b&gt;nº 2&lt;/b&gt;', $html);
        self::assertStringContainsString('href="/concursos/concurso-exemplo-2027"', $html);
        self::assertStringNotContainsString('Data do gabarito incluída', $html);
        self::assertStringContainsString('Revisão nº 3', $html);
    }
}
