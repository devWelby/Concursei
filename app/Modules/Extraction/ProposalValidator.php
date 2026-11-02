<?php

declare(strict_types=1);

namespace Concursei\Modules\Extraction;

use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\Vocabulary;

/**
 * Converte a resposta da IA (dado não confiável) em proposta revisável (D-019). Cada item sai com
 * valores normalizados e a lista de problemas; item com problema fica bloqueado e não pode ser aplicado.
 * A resposta bruta não é guardada: só esta proposta validada.
 */
final class ProposalValidator
{
    private const MAX_FEES = 10;
    private const MAX_EVENTS = 60;
    private const MAX_OFFERS = 300;

    /**
     * @param array<mixed> $data resposta estruturada do modelo
     * @return array{prompt_version:string,partial:bool,info:array<string,?string>,missing:list<string>,notes:list<string>,items:list<array<string,mixed>>}
     */
    public function validate(array $data, EvidenceMatcher $matcher, bool $partial): array
    {
        $notes = [];
        $items = [];
        foreach (['fees' => [self::MAX_FEES, 'f', 'fee', 'taxas'], 'events' => [self::MAX_EVENTS, 'e', 'event', 'datas'], 'offers' => [self::MAX_OFFERS, 'o', 'offer', 'cargos']] as $field => [$max, $prefix, $kind, $what]) {
            $list = is_array($data[$field] ?? null) ? array_values($data[$field]) : [];
            if (count($list) > $max) {
                $notes[] = "A resposta trouxe mais de {$max} {$what}; só as primeiras foram consideradas.";
                $list = array_slice($list, 0, $max);
            }
            $seen = [];
            foreach ($list as $i => $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $item = match ($kind) {
                    'fee' => $this->fee($raw, $matcher),
                    'event' => $this->event($raw, $matcher),
                    default => $this->offer($raw, $matcher),
                };
                $fingerprint = $kind . ':' . EvidenceMatcher::norm((string) json_encode($item['value']));
                if (isset($seen[$fingerprint])) {
                    $item['issues'][] = 'Item repetido na resposta.';
                }
                $seen[$fingerprint] = true;
                $item['key'] = $prefix . $i;
                $item['kind'] = $kind;
                $item['valid'] = $item['issues'] === [];
                $items[] = $item;
            }
        }

        $missing = [];
        foreach (is_array($data['missing'] ?? null) ? array_slice(array_values($data['missing']), 0, 30) : [] as $m) {
            $m = self::str($m, 150);
            if ($m !== null) {
                $missing[] = $m;
            }
        }
        $kind = self::str($data['document_kind'] ?? null, 30);
        return [
            'prompt_version' => EditalPrompt::VERSION,
            'partial' => $partial,
            'info' => [
                'document_kind' => in_array($kind, EditalPrompt::DOCUMENT_KINDS, true) ? $kind : 'outro',
                'contest_title' => self::str($data['contest_title'] ?? null, 200),
                'organization_name' => self::str($data['organization_name'] ?? null, 200),
                'exam_board_name' => self::str($data['exam_board_name'] ?? null, 200),
            ],
            'missing' => $missing,
            'notes' => $notes,
            'items' => $items,
        ];
    }

    /** @param array<mixed> $raw @return array<string,mixed> */
    private function fee(array $raw, EvidenceMatcher $matcher): array
    {
        $issues = [];
        $amountRaw = self::str($raw['amount'] ?? null, 40);
        $cents = self::money($amountRaw);
        if ($cents === null) {
            $issues[] = 'Valor da taxa ausente ou fora do formato R$ 1.234,56.';
        }
        $appliesTo = self::str($raw['applies_to'] ?? null, 100);
        $evidence = $this->evidence($raw, $matcher, $issues);
        if ($evidence !== null && $cents !== null && !EvidenceMatcher::moneyIn($cents, $evidence)) {
            $issues[] = 'O valor não aparece no trecho citado.';
        }
        return self::item(
            ['fee_cents' => $cents, 'applies_to' => $appliesTo],
            ($cents !== null ? Format::money($cents) : (string) $amountRaw) . ($appliesTo !== null ? ' — ' . $appliesTo : ''),
            $evidence,
            $raw,
            $issues
        );
    }

    /** @param array<mixed> $raw @return array<string,mixed> */
    private function event(array $raw, EvidenceMatcher $matcher): array
    {
        $issues = [];
        $type = self::str($raw['type'] ?? null, 40);
        if (!isset(Vocabulary::EVENT_TYPES[(string) $type])) {
            $issues[] = 'Tipo de data desconhecido.';
            $type = null;
        }
        $label = self::str($raw['label'] ?? null, 200);
        if ($type === 'other' && $label === null) {
            $issues[] = 'Evento "outro" sem descrição.';
        }
        $dateRaw = self::str($raw['date'] ?? null, 20);
        $parsed = $dateRaw !== null ? Format::parseEventDate($dateRaw) : false;
        if ($parsed === false || $parsed['precision'] === 'unknown') {
            $issues[] = 'Data ausente ou fora do formato DD/MM/AAAA, MM/AAAA ou AAAA.';
            $parsed = null;
        }
        $time = self::str($raw['time'] ?? null, 8);
        if ($time !== null) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $issues[] = 'Horário fora do formato HH:MM.';
                $time = null;
            } elseif ($parsed !== null && $parsed['precision'] !== 'day') {
                $issues[] = 'Horário informado sem data exata.';
            }
        }
        $evidence = $this->evidence($raw, $matcher, $issues);
        if ($evidence !== null && $parsed !== null && !EvidenceMatcher::dateIn((string) $parsed['date'], $parsed['precision'], $evidence)) {
            $issues[] = 'A data não aparece no trecho citado.';
        }
        if ($evidence !== null && $time !== null && !EvidenceMatcher::timeIn($time, $evidence)) {
            $issues[] = 'O horário não aparece no trecho citado.';
        }
        $value = [
            'type' => $type,
            'label' => $label,
            'date_value' => $parsed['date'] ?? null,
            'date_precision' => $parsed['precision'] ?? 'unknown',
            'time_local' => $time !== null ? $time . ':00' : null,
        ];
        $shown = Vocabulary::label(Vocabulary::EVENT_TYPES, $type, 'Tipo desconhecido') . ($label !== null ? ' (' . $label . ')' : '') . ': '
            . ($parsed !== null ? Format::eventDate($parsed['date'], $parsed['precision'], $value['time_local']) : (string) $dateRaw);
        return self::item($value, $shown, $evidence, $raw, $issues);
    }

    /** @param array<mixed> $raw @return array<string,mixed> */
    private function offer(array $raw, EvidenceMatcher $matcher): array
    {
        $issues = [];
        $title = self::str($raw['title'] ?? null, 200);
        if ($title === null || mb_strlen($title) < 2) {
            $issues[] = 'Cargo sem nome.';
        }
        $level = self::str($raw['education_level'] ?? null, 20);
        if (!isset(Vocabulary::EDUCATION_LEVELS[(string) $level])) {
            $issues[] = 'Escolaridade inválida.';
            $level = null;
        }
        $vacancies = self::int($raw['vacancies'] ?? null);
        if ($vacancies !== null && ($vacancies < 0 || $vacancies > 100000)) {
            $issues[] = 'Número de vagas inválido.';
            $vacancies = null;
        }
        $reserve = ($raw['reserve'] ?? false) === true;
        $salaryRaw = self::str($raw['salary'] ?? null, 60);
        $salary = self::money($salaryRaw);
        if ($salaryRaw !== null && $salary === null) {
            $issues[] = 'Remuneração fora do formato R$ 1.234,56.';
        }
        $workload = self::int($raw['workload_hours'] ?? null);
        if ($workload !== null && ($workload < 1 || $workload > 60)) {
            $issues[] = 'Carga horária inválida.';
            $workload = null;
        }
        $requirements = self::str($raw['requirements'] ?? null, 3000);

        $evidence = $this->evidence($raw, $matcher, $issues);
        if ($evidence !== null) {
            if ($title !== null && !EvidenceMatcher::textIn($title, $evidence)) {
                $issues[] = 'O nome do cargo não aparece no trecho citado.';
            }
            if ($level !== null && !EvidenceMatcher::levelIn($level, (string) $title, $evidence)) {
                $issues[] = 'A escolaridade não aparece no trecho citado.';
            }
            if ($vacancies !== null && !EvidenceMatcher::numberIn($vacancies, $evidence) && !($vacancies === 0 && EvidenceMatcher::reserveIn($evidence))) {
                $issues[] = 'O número de vagas não aparece no trecho citado.';
            }
            if ($reserve && !EvidenceMatcher::reserveIn($evidence)) {
                $issues[] = 'O cadastro de reserva não aparece no trecho citado.';
            }
            if ($salary !== null && !EvidenceMatcher::moneyIn($salary, $evidence)) {
                $issues[] = 'A remuneração não aparece no trecho citado.';
            }
            if ($workload !== null && !EvidenceMatcher::numberIn($workload, $evidence)) {
                $issues[] = 'A carga horária não aparece no trecho citado.';
            }
        }
        if ($requirements !== null && !$matcher->found($requirements)) {
            $issues[] = 'Os requisitos não foram encontrados no documento (precisam ser cópia literal).';
        }
        $value = [
            'title' => $title, 'education_level' => $level, 'vacancies' => $vacancies, 'reserve' => $reserve ? 1 : 0,
            'salary_cents' => $salary, 'workload_hours' => $workload, 'requirements' => $requirements,
        ];
        $shown = (string) $title . ' — ' . Vocabulary::label(Vocabulary::EDUCATION_LEVELS, $level, 'escolaridade ?')
            . ' — ' . Format::vacancies($vacancies, $reserve)
            . ($salary !== null ? ' — ' . Format::money($salary) : '')
            . ($workload !== null ? ' — ' . $workload . ' h/semana' : '');
        return self::item($value, $shown, $evidence, $raw, $issues);
    }

    /** @param array<mixed> $raw @param list<string> $issues */
    private function evidence(array $raw, EvidenceMatcher $matcher, array &$issues): ?string
    {
        $evidence = self::str($raw['evidence'] ?? null, 400);
        if ($evidence === null) {
            $issues[] = 'Sem trecho de evidência.';
            return null;
        }
        if (!$matcher->found($evidence)) {
            $issues[] = 'O trecho citado não foi encontrado no documento.';
            return null;
        }
        return $evidence;
    }

    /**
     * @param array<string,mixed> $value
     * @param array<mixed> $raw
     * @param list<string> $issues
     * @return array<string,mixed>
     */
    private static function item(array $value, string $shown, ?string $evidence, array $raw, array $issues): array
    {
        $page = self::int($raw['page'] ?? null);
        return [
            'value' => $value,
            'shown' => mb_substr($shown, 0, 400),
            'evidence' => $evidence ?? self::str($raw['evidence'] ?? null, 400),
            'page' => $page !== null && $page > 0 && $page < 10000 ? $page : null,
            'issues' => $issues,
        ];
    }

    private static function str(mixed $v, int $max): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function int(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e9) {
            return (int) $v;
        }
        return is_string($v) && preg_match('/^\d{1,9}$/', $v) ? (int) $v : null;
    }

    /** Primeiro valor em reais da string ("R$ 13.994,78 (vencimento)" → 1399478). */
    private static function money(?string $v): ?int
    {
        if ($v === null || !preg_match('/(\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d+(?:,\d{1,2})?)/u', $v, $m)) {
            return null;
        }
        $cents = Format::parseMoney($m[1]);
        return is_int($cents) ? $cents : null;
    }
}
