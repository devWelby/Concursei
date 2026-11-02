<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

/**
 * Filtros da listagem pública vindos da query string. Valor fora da
 * allowlist é descartado em silêncio (nunca ecoado nem levado ao SQL).
 */
final class ContestFilters
{
    public const MAX_PAGE = 500;

    private function __construct(
        public readonly ?string $q,
        public readonly ?string $uf,
        public readonly ?string $region,
        public readonly ?string $area,
        public readonly ?string $level,
        public readonly ?string $organization,
        public readonly ?string $board,
        public readonly ?string $situation,
        public readonly string $sort,
        public readonly int $page,
    ) {
    }

    /** @param array<string,mixed> $query */
    public static function fromQuery(array $query): self
    {
        $str = static fn (string $k): ?string => isset($query[$k]) && is_string($query[$k]) && trim($query[$k]) !== '' ? trim($query[$k]) : null;
        $pick = static fn (string $k, array $allowed): ?string => ($v = $str($k)) !== null && array_key_exists($v, $allowed) ? $v : null;
        $slug = static fn (string $k): ?string => ($v = $str($k)) !== null && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v) && strlen($v) <= 190 ? $v : null;

        $q = $str('q');
        if ($q !== null) {
            $q = mb_substr((string) preg_replace('/[\p{C}\s]+/u', ' ', $q), 0, 100);
            $q = mb_strlen(trim($q)) >= 2 ? trim($q) : null;
        }
        $uf = $str('uf');
        $uf = $uf !== null && ($uf === 'nacional' || isset(Vocabulary::UFS[strtoupper($uf)])) ? ($uf === 'nacional' ? $uf : strtoupper($uf)) : null;
        $page = $str('pagina');
        $page = $page !== null && preg_match('/^[1-9]\d{0,3}$/', $page) ? min((int) $page, self::MAX_PAGE) : 1;

        return new self(
            $q,
            $uf,
            $pick('regiao', Vocabulary::REGIONS),
            $pick('area', Vocabulary::AREAS),
            $pick('escolaridade', Vocabulary::EDUCATION_LEVELS),
            $slug('orgao'),
            $slug('banca'),
            $pick('situacao', Vocabulary::SITUATIONS),
            $pick('ordenar', Vocabulary::SORTS) ?? 'recentes',
            $page,
        );
    }

    /** Parâmetros normalizados para links (paginação, remoção de filtro). @return array<string,string|int> */
    public function toQuery(array $override = []): array
    {
        $q = array_filter([
            'q' => $this->q, 'uf' => $this->uf, 'regiao' => $this->region, 'area' => $this->area, 'escolaridade' => $this->level,
            'orgao' => $this->organization, 'banca' => $this->board, 'situacao' => $this->situation,
            'ordenar' => $this->sort !== 'recentes' ? $this->sort : null, 'pagina' => $this->page > 1 ? $this->page : null,
        ], static fn ($v): bool => $v !== null);
        foreach ($override as $k => $v) {
            if ($v === null) {
                unset($q[$k]);
            } else {
                $q[$k] = $v;
            }
        }
        return $q;
    }

    public function hasFilters(): bool
    {
        return $this->q !== null || $this->uf !== null || $this->region !== null || $this->area !== null || $this->level !== null
            || $this->organization !== null || $this->board !== null || $this->situation !== null;
    }
}
