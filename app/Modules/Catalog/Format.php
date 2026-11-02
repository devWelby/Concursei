<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use DateTimeImmutable;

/** Formatação pt-BR do catálogo. Valores monetários sempre em centavos (inteiros). */
final class Format
{
    private const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    public static function money(?int $cents): string
    {
        if ($cents === null) {
            return 'Não informado';
        }
        return 'R$ ' . number_format(intdiv($cents, 100), 0, ',', '.') . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Converte "12.345,67", "R$ 1500" ou "9,9" em centavos sem usar float.
     * @return int|null|false null = vazio; false = formato inválido
     */
    public static function parseMoney(string $input): int|null|false
    {
        $v = trim(str_replace(['R$', ' ', "\u{00A0}"], '', $input));
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?$/', $v, $m)) {
            return false;
        }
        $reais = (int) str_replace('.', '', $m[1]);
        $cents = isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0;
        if ($reais > 20_000_000) {
            return false;
        }
        return $reais * 100 + $cents;
    }

    /** Valor em centavos para preencher formulários ("12345,67"). */
    public static function moneyInput(?int $cents): string
    {
        return $cents === null ? '' : intdiv($cents, 100) . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function eventDate(?string $date, string $precision, ?string $time = null): string
    {
        if ($date === null || $precision === 'unknown') {
            return 'A definir';
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false) {
            return 'A definir';
        }
        return match ($precision) {
            'year' => $d->format('Y') . ' (previsto)',
            'month' => self::MONTHS[(int) $d->format('n') - 1] . ' de ' . $d->format('Y') . ' (previsto)',
            default => $d->format('d/m/Y') . ($time !== null && $time !== '' ? ' às ' . self::time($time) : ''),
        };
    }

    public static function date(?string $date): string
    {
        $d = $date !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10)) : false;
        return $d ? $d->format('d/m/Y') : '—';
    }

    public static function monthLabel(DateTimeImmutable $d): string
    {
        return ucfirst(self::MONTHS[(int) $d->format('n') - 1]) . ' de ' . $d->format('Y');
    }

    private static function time(string $time): string
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [1 => 0]);
        return $m === 0 ? sprintf('%dh', $h) : sprintf('%dh%02d', $h, $m);
    }

    public static function vacancies(?int $vacancies, bool $reserve): string
    {
        if ($vacancies !== null && $vacancies > 0) {
            $txt = $vacancies . ($vacancies === 1 ? ' vaga' : ' vagas');
            return $reserve ? $txt . ' + cadastro de reserva' : $txt;
        }
        return $reserve ? 'Cadastro de reserva' : 'Não informado';
    }

    /** "hoje", "amanhã", "em N dias" ou null se a data já passou. */
    public static function countdown(string $date, DateTimeImmutable $today): ?string
    {
        $target = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-d'));
        if ($target === false || $base === false || $target < $base) {
            return null;
        }
        $days = (int) $base->diff($target)->days;
        return match ($days) {
            0 => 'hoje',
            1 => 'amanhã',
            default => "em {$days} dias",
        };
    }

    public static function slugify(string $text): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ª' => '', 'º' => '',
        ];
        $s = strtr(mb_strtolower(trim($text)), $map);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        return substr(trim($s, '-'), 0, 180);
    }
}
