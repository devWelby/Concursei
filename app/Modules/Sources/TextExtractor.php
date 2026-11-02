<?php

declare(strict_types=1);

namespace Concursei\Modules\Sources;

use DOMDocument;
use DOMXPath;
use Smalot\PdfParser\Config as PdfConfig;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Texto de documentos coletados (dado NÃO confiável: só é lido, nunca executado ou interpretado como instrução).
 * - PDF textual via smalot/pdfparser com limite de descompressão (proteção contra bombas).
 * - HTML via DOM com LIBXML_NONET (sem rede, sem entidades externas); scripts e estilos descartados.
 * - PDF sem texto (digitalizado) ou protegido recebe estado próprio e vai para revisão manual: sem OCR presumido.
 */
class TextExtractor
{
    public const MAX_CHARS = 2_000_000;
    /** Menos que isso por página indica PDF digitalizado (imagem). */
    private const MIN_CHARS_PER_PAGE = 40;

    public function extract(string $bytes, string $mime): ExtractedText
    {
        return match ($mime) {
            'application/pdf' => $this->pdf($bytes),
            'text/html', 'application/xhtml+xml' => $this->html($bytes),
            'text/plain' => $this->finish(self::toUtf8($bytes), null, null),
            default => new ExtractedText('unsupported', note: 'Tipo sem extrator de texto: ' . $mime),
        };
    }

    private function pdf(string $bytes): ExtractedText
    {
        if (!str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF-')) {
            return new ExtractedText('failed', note: 'Arquivo não é um PDF válido.');
        }
        $config = new PdfConfig();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);
        try {
            $pdf = (new PdfParser([], $config))->parseContent($bytes);
            $parts = [];
            foreach ($pdf->getPages() as $i => $page) {
                $parts[] = '[[página ' . ($i + 1) . "]]\n" . self::normalize($page->getText());
            }
            $title = $pdf->getDetails()['Title'] ?? null;
        } catch (Throwable $e) {
            return str_contains(strtolower($e->getMessage()), 'secured')
                ? new ExtractedText('unsupported', note: 'PDF protegido por senha/criptografia: revisão manual.')
                : new ExtractedText('failed', note: 'PDF ilegível pelo extrator: revisão manual.');
        }
        $pages = count($parts);
        $text = implode("\n\n", $parts);
        $plain = preg_replace('/\[\[página \d+\]\]|\s+/u', '', $text) ?? '';
        if ($pages === 0 || mb_strlen($plain) < self::MIN_CHARS_PER_PAGE * max(1, $pages)) {
            return new ExtractedText('empty', pages: $pages, note: 'Sem texto extraível (provável PDF digitalizado): revisão manual.');
        }
        return $this->finish($text, $pages, is_string($title) ? self::toUtf8($title) : null);
    }

    private function html(string $bytes): ExtractedText
    {
        $dom = self::loadHtml($bytes);
        if ($dom === null) {
            return new ExtractedText('failed', note: 'HTML ilegível.');
        }
        $xpath = new DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//script|//style|//noscript|//template|//svg|//iframe|//object') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
        // Quebras de linha nos elementos de bloco para preservar a estrutura do texto.
        foreach (iterator_to_array($xpath->query('//p|//div|//li|//tr|//br|//h1|//h2|//h3|//h4|//h5|//h6|//section|//article|//table|//dt|//dd') ?: []) as $node) {
            $node->parentNode?->insertBefore($dom->createTextNode("\n"), $node);
            $node->appendChild($dom->createTextNode("\n"));
        }
        foreach (iterator_to_array($xpath->query('//td|//th') ?: []) as $node) {
            $node->appendChild($dom->createTextNode(' | '));
        }
        $title = trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
        $body = $xpath->query('//body')->item(0) ?? $dom->documentElement;
        return $this->finish(self::normalize((string) $body?->textContent), null, $title !== '' ? $title : null);
    }

    private function finish(string $text, ?int $pages, ?string $title): ExtractedText
    {
        $text = self::normalize($text);
        if (trim($text) === '') {
            return new ExtractedText('empty', pages: $pages, title: $title, note: 'Documento sem texto.');
        }
        $note = null;
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
            $note = 'Texto truncado no limite de ' . number_format(self::MAX_CHARS, 0, ',', '.') . ' caracteres.';
        }
        return new ExtractedText('ready', $text, $pages, $title, $note);
    }

    /** @return list<string> links absolutos (href) encontrados em uma página HTML */
    public static function links(string $html): array
    {
        $dom = self::loadHtml($html);
        if ($dom === null) {
            return [];
        }
        $links = [];
        foreach ((new DOMXPath($dom))->query('//a[@href]') ?: [] as $a) {
            $href = trim((string) $a->attributes?->getNamedItem('href')?->nodeValue);
            if ($href !== '' && !str_starts_with($href, '#') && !preg_match('/^(javascript|mailto|tel|data):/i', $href)) {
                $links[] = $href;
            }
        }
        return array_values(array_unique($links));
    }

    /** Normalização usada também na conferência de evidências (mesmo texto, mesma regra). */
    public static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{200B}", "\u{FEFF}"], ["\n", "\n", ' ', '', ''], $text);
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }

    public static function toUtf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        return (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    private static function loadHtml(string $html): ?DOMDocument
    {
        $html = self::toUtf8($html);
        // Remove a declaração de charset original: o conteúdo já está em UTF-8.
        $html = (string) preg_replace('/<meta[^>]+charset[^>]*>/i', '', $html);
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $ok ? $dom : null;
    }
}
