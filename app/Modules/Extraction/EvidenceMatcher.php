<?php

declare(strict_types=1);

namespace Concursei\Modules\Extraction;

use Concursei\Modules\Sources\TextExtractor;

/**
 * Confere evidências contra o texto realmente coletado (D-019). Um trecho só vale se existir no documento
 * (comparação sem diferença de espaços, caixa, acentos, aspas e hifenização de quebra de linha), e o valor
 * proposto precisa aparecer no próprio trecho. Confiança declarada pelo modelo não entra na decisão.
 */
final class EvidenceMatcher
{
    private const MONTHS = ['janeiro', 'fevereiro', 'marco', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ];
    private const LEVELS = [
        'superior' => '/superior|graduac|bacharel|licenciatura|diploma de curso/u',
        'medio' => '/medio/u',
        'tecnico' => '/(curso|nivel|ensino medio|formacao) tecnic|tecnico de nivel medio|tecnico profissionalizante/u',
        'fundamental' => '/fundamental/u',
    ];

    private string $haystack;

    public function __construct(string $documentText)
    {
        $text = (string) preg_replace('/\[\[página \d+\]\]/u', ' ', $documentText);
        $text = (string) preg_replace('/(\p{L})-\n(\p{L})/u', '$1$2', $text);
        $this->haystack = self::norm($text);
    }

    public static function norm(string $s): string
    {
        $s = TextExtractor::normalize($s);
        $s = str_replace(['“', '”', '„', '‘', '’', '–', '—', '‐', "\u{00AD}", 'º', '°', 'ª'], ['"', '"', '"', "'", "'", '-', '-', '-', '', 'o', 'o', 'a'], $s);
        $s = strtr(mb_strtolower($s), self::ACCENTS);
        $s = (string) preg_replace('/\p{Mn}+/u', '', $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** O trecho existe no documento? Reticências separam partes que precisam aparecer em ordem. */
    public function found(string $evidence): bool
    {
        $parts = [];
        foreach (preg_split('/\.{3}|…/u', $evidence) ?: [] as $p) {
            $p = self::norm($p);
            if ($p !== '') {
                $parts[] = $p;
            }
        }
        if ($parts === [] || mb_strlen(implode('', $parts)) < 8) {
            return false;
        }
        $offset = 0;
        foreach ($parts as $part) {
            if (mb_strlen($part) < 4) {
                return false;
            }
            $pos = mb_strpos($this->haystack, $part, $offset);
            if ($pos === false) {
                return false;
            }
            $offset = $pos + mb_strlen($part);
        }
        return true;
    }

    public static function textIn(string $value, string $evidence): bool
    {
        $v = self::norm($value);
        return $v !== '' && str_contains(self::norm($evidence), $v);
    }

    /** Número inteiro no trecho, com ou sem separador de milhar ("1.000" ou "1000"). */
    public static function numberIn(int $n, string $evidence): bool
    {
        $e = self::norm($evidence);
        foreach (array_unique([(string) $n, number_format($n, 0, ',', '.')]) as $form) {
            if (preg_match('/(?<![\d.,])' . preg_quote($form, '/') . '(?!\d|[.,]\d)/u', $e) === 1) {
                return true;
            }
        }
        return false;
    }

    /** Valor em centavos no trecho ("1.234,56", "1234,56"; "R$ 120" também vale para R$ 120,00). */
    public static function moneyIn(int $cents, string $evidence): bool
    {
        $e = str_replace(' ', '', self::norm($evidence));
        $reais = intdiv($cents, 100);
        $centavos = sprintf('%02d', $cents % 100);
        $forms = [number_format($reais, 0, ',', '.') . ',' . $centavos, $reais . ',' . $centavos];
        if ($centavos === '00') {
            $forms[] = 'r$' . number_format($reais, 0, ',', '.');
            $forms[] = 'r$' . $reais;
        }
        foreach (array_unique($forms) as $form) {
            if (preg_match('/(?<![\d.,])' . preg_quote($form, '/') . '(?!\d|[.,]\d)/u', $e) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Data (AAAA-MM-DD) sustentada pelo trecho. Dia exato: "10/03", "10.03", "10 de março" ou intervalos
     * ("10 a 20/03", "10 a 20 de março"), com o ano presente no trecho. Mês: "03/2027" ou "março de 2027".
     */
    public static function dateIn(string $ymd, string $precision, string $evidence): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return false;
        }
        [$y, $mo, $d] = [$m[1], (int) $m[2], (int) $m[3]];
        $e = self::norm($evidence);
        $month = self::MONTHS[$mo - 1];
        $hasYear = preg_match('/(?<!\d)' . $y . '(?!\d)/u', $e) === 1;
        if ($precision === 'year') {
            return $hasYear;
        }
        if ($precision === 'month') {
            return preg_match('#(?<![\d/.])0?' . $mo . '\s*/\s*' . $y . '(?!\d)#u', $e) === 1
                || preg_match('#' . $month . '\s*(?:de|/)?\s*' . $y . '(?!\d)#u', $e) === 1;
        }
        $range = '(?:\s*(?:a|e|ate)\s*\d{1,2}o?)?';
        $numeric = '#(?<![\d/.])0?' . $d . $range . '\s*[/.]\s*0?' . $mo . '(?!\d)#u';
        $textual = '#(?<![\d/.])0?' . $d . 'o?' . $range . '\s+(?:de\s+)?' . $month . '#u';
        return $hasYear && (preg_match($numeric, $e) === 1 || preg_match($textual, $e) === 1);
    }

    /** Horário "HH:MM" no trecho: "13h", "13h30", "13:30", "13 horas". */
    public static function timeIn(string $hhmm, string $evidence): bool
    {
        if (!preg_match('/^(\d{2}):(\d{2})/', $hhmm, $m)) {
            return false;
        }
        $h = (string) (int) $m[1];
        $e = self::norm($evidence);
        $pattern = $m[2] === '00'
            ? '/(?<!\d)0?' . $h . '\s*(?:h(?:\s*00)?(?:min)?|:\s*00|horas?)(?!\d)/u'
            : '/(?<!\d)0?' . $h . '\s*(?:h|:)\s*' . $m[2] . '(?!\d)/u';
        return preg_match($pattern, $e) === 1;
    }

    /** Escolaridade sustentada pelo trecho, desconsiderando o nome do cargo ("Técnico Judiciário" não prova nível técnico). */
    public static function levelIn(string $level, string $title, string $evidence): bool
    {
        if (!isset(self::LEVELS[$level])) {
            return false;
        }
        $e = self::norm($evidence);
        $t = self::norm($title);
        if ($t !== '') {
            $e = str_replace($t, ' ', $e);
        }
        return preg_match(self::LEVELS[$level], $e) === 1;
    }

    public static function reserveIn(string $evidence): bool
    {
        return preg_match('/cadastro de reserva|(?<![a-z])cr(?![a-z])/u', self::norm($evidence)) === 1;
    }
}
