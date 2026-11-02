<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Catalog;

use Concursei\Modules\Catalog\ContestFilters;
use PHPUnit\Framework\TestCase;

final class ContestFiltersTest extends TestCase
{
    public function testValidValuesAreNormalized(): void
    {
        $f = ContestFilters::fromQuery(['q' => "  tribunal \n regional ", 'uf' => 'sp', 'regiao' => 'sudeste', 'area' => 'tribunais', 'escolaridade' => 'superior',
            'orgao' => 'trt-2', 'banca' => 'banca-x', 'situacao' => 'abertos', 'ordenar' => 'vagas', 'pagina' => '3']);
        self::assertSame(['tribunal regional', 'SP', 'sudeste', 'tribunais', 'superior', 'trt-2', 'banca-x', 'abertos', 'vagas', 3],
            [$f->q, $f->uf, $f->region, $f->area, $f->level, $f->organization, $f->board, $f->situation, $f->sort, $f->page]);
        self::assertTrue($f->hasFilters());
        self::assertSame(['q' => 'tribunal regional', 'uf' => 'SP', 'regiao' => 'sudeste', 'area' => 'tribunais', 'escolaridade' => 'superior',
            'orgao' => 'trt-2', 'banca' => 'banca-x', 'situacao' => 'abertos', 'ordenar' => 'vagas'], $f->toQuery(['pagina' => null]));
    }

    public function testInvalidValuesAreDroppedSilently(): void
    {
        $f = ContestFilters::fromQuery(['q' => 'a', 'uf' => 'XX', 'regiao' => 'lua', 'area' => "' OR 1=1", 'escolaridade' => ['superior'],
            'orgao' => '../etc', 'banca' => 'Banca X', 'situacao' => 'todos', 'ordenar' => 'id; DROP', 'pagina' => '0']);
        self::assertSame([null, null, null, null, null, null, null, null, 'recentes', 1],
            [$f->q, $f->uf, $f->region, $f->area, $f->level, $f->organization, $f->board, $f->situation, $f->sort, $f->page]);
        self::assertFalse($f->hasFilters());
        self::assertSame([], $f->toQuery());
    }

    public function testLimits(): void
    {
        self::assertSame(ContestFilters::MAX_PAGE, ContestFilters::fromQuery(['pagina' => '9999'])->page);
        self::assertSame(100, mb_strlen((string) ContestFilters::fromQuery(['q' => str_repeat('é', 300)])->q));
        self::assertSame('nacional', ContestFilters::fromQuery(['uf' => 'nacional'])->uf);
    }
}
