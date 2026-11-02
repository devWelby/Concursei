<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use DateTimeImmutable;

/**
 * Fase pública derivada da situação informada + datas com precisão de DIA.
 * Datas só com mês/ano ou desconhecidas nunca abrem/fecham janelas.
 * Regra única usada pela publicação, pelo Cron diário e pelas páginas.
 */
final class ContestPhase
{
    private const TERMINAL = ['suspended', 'cancelled', 'concluded'];

    /** @param list<array{type:string,date:?string,precision:string}> $events */
    public static function derive(string $status, array $events, DateTimeImmutable $today): string
    {
        if (in_array($status, self::TERMINAL, true)) {
            return $status;
        }
        $t = $today->format('Y-m-d');
        $dates = self::dayDates($events);

        $passed = static fn (string $type): bool => isset($dates[$type]) && min($dates[$type]) <= $t;
        if ($passed('result')) {
            return 'result';
        }
        if ($passed('answer_key')) {
            return 'answer_key';
        }
        // Com várias provas, "realizada" só depois da última.
        if (isset($dates['exam']) && max($dates['exam']) <= $t) {
            return 'exam_done';
        }
        $start = isset($dates['registration_start']) ? min($dates['registration_start']) : null;
        $end = isset($dates['registration_end']) ? max($dates['registration_end']) : null;
        if ($end !== null && $end < $t) {
            return 'registration_closed';
        }
        if ($start !== null && $start <= $t) {
            return 'registration_open';
        }
        if ($status === 'edital_published' || $passed('edital')) {
            return 'edital_published';
        }
        return $status;
    }

    /**
     * Próximo evento com data exata a partir de hoje (inclusive).
     * @param list<array{type:string,date:?string,precision:string}> $events
     * @return array{type:string,date:string}|null
     */
    public static function nextEvent(array $events, DateTimeImmutable $today): ?array
    {
        $t = $today->format('Y-m-d');
        $best = null;
        foreach ($events as $e) {
            if ($e['precision'] !== 'day' || $e['date'] === null || $e['date'] < $t) {
                continue;
            }
            if ($best === null || $e['date'] < $best['date']) {
                $best = ['type' => $e['type'], 'date' => $e['date']];
            }
        }
        return $best;
    }

    /** @param list<array{type:string,date:?string,precision:string}> $events */
    public static function registrationEnd(array $events): ?string
    {
        $dates = self::dayDates($events)['registration_end'] ?? [];
        return $dates === [] ? null : max($dates);
    }

    /** @return array<string,list<string>> */
    private static function dayDates(array $events): array
    {
        $out = [];
        foreach ($events as $e) {
            if (($e['precision'] ?? '') === 'day' && is_string($e['date'] ?? null)) {
                $out[$e['type']][] = $e['date'];
            }
        }
        return $out;
    }
}
