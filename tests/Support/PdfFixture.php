<?php

declare(strict_types=1);

namespace Concursei\Tests\Support;

/** Gera PDFs sintéticos mínimos (texto ASCII em Helvetica) para testes do extrator. */
final class PdfFixture
{
    /** @param list<list<string>> $pages linhas de texto por página (página vazia = sem texto, como um PDF digitalizado) */
    public static function make(array $pages): string
    {
        $objects = [];
        $kids = [];
        $fontId = 3 + 2 * count($pages);
        foreach ($pages as $i => $lines) {
            $pageId = 3 + 2 * $i;
            $contentId = $pageId + 1;
            $kids[] = $pageId . ' 0 R';
            $stream = $lines === [] ? '' : 'BT /F1 11 Tf 14 TL 72 760 Td ' . implode(' T* ', array_map(
                static fn (string $l): string => '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $l) . ') Tj',
                $lines
            )) . ' ET';
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$contentId} 0 R /Resources << /Font << /F1 {$fontId} 0 R >> >> >>";
            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
