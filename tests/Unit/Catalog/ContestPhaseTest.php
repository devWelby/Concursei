<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Catalog;

use Concursei\Modules\Catalog\ContestPhase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContestPhaseTest extends TestCase
{
    private static function ev(string $type, ?string $date, string $precision = 'day'): array
    {
        return ['type' => $type, 'date' => $date, 'precision' => $precision];
    }

    /** @return iterable<string,array{string,list<array>,string,string}> */
    public static function cases(): iterable
    {
        $reg = [self::ev('registration_start', '2027-03-01'), self::ev('registration_end', '2027-03-31'), self::ev('exam', '2027-05-10')];
        yield 'previsto sem datas' => ['predicted', [], '2027-01-01', 'predicted'];
        yield 'autorizado' => ['authorized', [], '2027-01-01', 'authorized'];
        yield 'edital antes das inscrições' => ['edital_published', $reg, '2027-02-15', 'edital_published'];
        yield 'primeiro dia de inscrição' => ['edital_published', $reg, '2027-03-01', 'registration_open'];
        yield 'último dia de inscrição' => ['edital_published', $reg, '2027-03-31', 'registration_open'];
        yield 'dia seguinte ao fim' => ['edital_published', $reg, '2027-04-01', 'registration_closed'];
        yield 'dia da prova' => ['edital_published', $reg, '2027-05-10', 'exam_done'];
        yield 'gabarito' => ['edital_published', [...$reg, self::ev('answer_key', '2027-05-12')], '2027-05-12', 'answer_key'];
        yield 'resultado' => ['edital_published', [...$reg, self::ev('answer_key', '2027-05-12'), self::ev('result', '2027-06-30')], '2027-07-01', 'result'];
        yield 'suspenso prevalece' => ['suspended', $reg, '2027-03-10', 'suspended'];
        yield 'cancelado prevalece' => ['cancelled', $reg, '2027-03-10', 'cancelled'];
        yield 'concluído prevalece' => ['concluded', $reg, '2027-03-10', 'concluded'];
        yield 'mês não abre inscrição' => ['edital_published', [self::ev('registration_start', '2027-03-01', 'month')], '2027-03-20', 'edital_published'];
        yield 'data desconhecida ignorada' => ['authorized', [self::ev('exam', null, 'unknown')], '2027-03-20', 'authorized'];
        yield 'inscrição sem fim informado' => ['edital_published', [self::ev('registration_start', '2027-03-01')], '2027-03-20', 'registration_open'];
        yield 'várias provas: só após a última' => ['edital_published', [self::ev('registration_end', '2027-03-31'), self::ev('exam', '2027-05-10'), self::ev('exam', '2027-06-10')], '2027-05-20', 'registration_closed'];
    }

    #[DataProvider('cases')]
    public function testDerive(string $status, array $events, string $today, string $expected): void
    {
        self::assertSame($expected, ContestPhase::derive($status, $events, new DateTimeImmutable($today)));
    }

    public function testNextEventIgnoresPastAndImpreciseDates(): void
    {
        $events = [
            self::ev('registration_start', '2027-03-01'),
            self::ev('exam', '2027-05-01', 'month'),
            self::ev('registration_end', '2027-03-31'),
            self::ev('result', null, 'unknown'),
        ];
        self::assertSame(['type' => 'registration_end', 'date' => '2027-03-31'], ContestPhase::nextEvent($events, new DateTimeImmutable('2027-03-10')));
        self::assertSame(['type' => 'registration_end', 'date' => '2027-03-31'], ContestPhase::nextEvent($events, new DateTimeImmutable('2027-03-31')), 'Evento de hoje ainda é o próximo');
        self::assertNull(ContestPhase::nextEvent($events, new DateTimeImmutable('2027-04-01')));
    }

    public function testRegistrationEnd(): void
    {
        self::assertSame('2027-03-31', ContestPhase::registrationEnd([self::ev('registration_end', '2027-03-31')]));
        self::assertNull(ContestPhase::registrationEnd([self::ev('registration_end', '2027-03-01', 'month')]));
    }
}
