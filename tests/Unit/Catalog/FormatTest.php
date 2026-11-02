<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Catalog;

use Concursei\Modules\Catalog\Format;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testMoneyFromCents(): void
    {
        self::assertSame('R$ 12.345,67', Format::money(1234567));
        self::assertSame('R$ 0,05', Format::money(5));
        self::assertSame('Não informado', Format::money(null));
        self::assertSame(1234567, Format::parseMoney('12.345,67'));
        self::assertSame(1234567, Format::parseMoney('R$ 12345,67'));
        self::assertSame(150000, Format::parseMoney('1500'));
        self::assertSame(990, Format::parseMoney('9,9'));
        self::assertNull(Format::parseMoney(''));
        self::assertFalse(Format::parseMoney('12,345,67'));
        self::assertFalse(Format::parseMoney('abc'));
        self::assertFalse(Format::parseMoney('-10'));
    }

    public function testDatesRespectPrecision(): void
    {
        self::assertSame('15/03/2027', Format::eventDate('2027-03-15', 'day'));
        self::assertSame('15/03/2027 às 13h30', Format::eventDate('2027-03-15', 'day', '13:30:00'));
        self::assertSame('março de 2027 (previsto)', Format::eventDate('2027-03-01', 'month'));
        self::assertSame('2027 (previsto)', Format::eventDate('2027-01-01', 'year'));
        self::assertSame('A definir', Format::eventDate(null, 'unknown'));
    }

    public function testEventDateInputDerivesPrecision(): void
    {
        self::assertSame(['date' => '2027-03-15', 'precision' => 'day'], Format::parseEventDate('15/03/2027'));
        self::assertSame(['date' => '2027-03-05', 'precision' => 'day'], Format::parseEventDate('5/3/2027'));
        self::assertSame(['date' => '2027-03-01', 'precision' => 'month'], Format::parseEventDate('03/2027'));
        self::assertSame(['date' => '2027-01-01', 'precision' => 'year'], Format::parseEventDate(' 2027 '));
        self::assertSame(['date' => null, 'precision' => 'unknown'], Format::parseEventDate(''));
        self::assertFalse(Format::parseEventDate('31/02/2027'), 'Dia inexistente');
        self::assertFalse(Format::parseEventDate('13/2027'));
        self::assertFalse(Format::parseEventDate('1999'), 'Fora do intervalo aceito');
        self::assertFalse(Format::parseEventDate('2027-03-15'));
        self::assertFalse(Format::parseEventDate('amanhã'));

        self::assertSame('15/03/2027', Format::eventDateInput('2027-03-15', 'day'));
        self::assertSame('03/2027', Format::eventDateInput('2027-03-01', 'month'));
        self::assertSame('2027', Format::eventDateInput('2027-01-01', 'year'));
        self::assertSame('', Format::eventDateInput(null, 'unknown'));
    }

    public function testVacancies(): void
    {
        self::assertSame('120 vagas', Format::vacancies(120, false));
        self::assertSame('1.000 vagas + cadastro de reserva', Format::vacancies(1000, true));
        self::assertSame('1 vaga + cadastro de reserva', Format::vacancies(1, true));
        self::assertSame('Cadastro de reserva', Format::vacancies(null, true));
        self::assertSame('Cadastro de reserva', Format::vacancies(0, true));
        self::assertSame('Não informado', Format::vacancies(null, false));
    }

    public function testCountdown(): void
    {
        $today = new DateTimeImmutable('2027-03-10');
        self::assertSame('hoje', Format::countdown('2027-03-10', $today));
        self::assertSame('amanhã', Format::countdown('2027-03-11', $today));
        self::assertSame('em 21 dias', Format::countdown('2027-03-31', $today));
        self::assertNull(Format::countdown('2027-03-09', $today));
    }

    public function testSlugify(): void
    {
        self::assertSame('tribunal-regional-do-trabalho-2-regiao', Format::slugify('Tribunal Regional do Trabalho — 2ª Região'));
        self::assertSame('sao-paulo-2027', Format::slugify('  São Paulo  2027 '));
    }
}
