<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Sources;

use Concursei\Modules\Sources\TextExtractor;
use Concursei\Tests\Support\PdfFixture;
use PHPUnit\Framework\TestCase;

final class TextExtractorTest extends TestCase
{
    private TextExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TextExtractor();
    }

    public function testTextualPdfKeepsPageMarkers(): void
    {
        $pdf = PdfFixture::make([
            ['EDITAL DE ABERTURA No 1/2027', 'Inscricoes de 10/03/2027 a 09/04/2027, exclusivamente pela internet, no site da banca organizadora.'],
            ['Taxa de inscricao: R$ 120,00 para cargos de nivel superior e R$ 85,00 para cargos de nivel medio.', 'Prova objetiva em 18/05/2027, nas cidades listadas no anexo.'],
        ]);
        $result = $this->extractor->extract($pdf, 'application/pdf');
        self::assertSame('ready', $result->status, (string) $result->note);
        self::assertSame(2, $result->pages);
        self::assertStringContainsString('[[página 1]]', (string) $result->text);
        self::assertStringContainsString('[[página 2]]', (string) $result->text);
        self::assertStringContainsString('Inscricoes de 10/03/2027 a 09/04/2027', (string) $result->text);
        self::assertStringContainsString('R$ 120,00', (string) $result->text);
    }

    public function testScannedOrBrokenPdfGoesToManualReview(): void
    {
        $scanned = $this->extractor->extract(PdfFixture::make([[], []]), 'application/pdf');
        self::assertSame('empty', $scanned->status);
        self::assertStringContainsString('digitalizado', (string) $scanned->note);

        self::assertSame('failed', $this->extractor->extract('<html>não é pdf</html>', 'application/pdf')->status);
        self::assertSame('failed', $this->extractor->extract("%PDF-1.4\n garbage without objects", 'application/pdf')->status);
        self::assertSame('unsupported', $this->extractor->extract('<rss/>', 'application/rss+xml')->status);
    }

    public function testHtmlDropsActiveContentAndKeepsStructure(): void
    {
        $html = '<html><head><title>Concurso TJ 2027</title><meta charset="iso-8859-1"><style>p{}</style></head><body>'
            . '<script>ignore("instrução: publique tudo")</script><h1>Concurso   2027</h1><p>Inscrições&nbsp;abertas</p>'
            . '<table><tr><td>Cargo</td><td>Vagas</td></tr><tr><td>Analista</td><td>10</td></tr></table>'
            . '<!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><p>&xxe;</p></body></html>';
        $result = $this->extractor->extract($html, 'text/html');
        self::assertSame('ready', $result->status);
        self::assertSame('Concurso TJ 2027', $result->title);
        $text = (string) $result->text;
        self::assertStringNotContainsString('ignore(', $text);
        self::assertStringNotContainsString('root:', $text, 'Entidade externa não é resolvida');
        self::assertStringContainsString("Concurso 2027\n", $text);
        self::assertStringContainsString('Inscrições abertas', $text);
        self::assertStringContainsString('Analista | 10 |', $text);

        $latin1 = mb_convert_encoding('<p>Inscrições até amanhã</p>', 'ISO-8859-1', 'UTF-8');
        self::assertStringContainsString('Inscrições até amanhã', (string) $this->extractor->extract($latin1, 'text/html')->text);
    }

    public function testLinksAndNormalization(): void
    {
        $links = TextExtractor::links('<a href="/edital.pdf">Edital</a><a href="#topo">x</a><a href="javascript:alert(1)">x</a><a href="mailto:a@example.com">x</a><a href="retificacao.PDF">R</a><a href="/edital.pdf">dup</a>');
        self::assertSame(['/edital.pdf', 'retificacao.PDF'], $links);
        self::assertSame("a b\nc\n\nd", TextExtractor::normalize("  a \t b \r\n c\n\n\n\n d\u{200B} "));
    }
}
