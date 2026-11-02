<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Extraction;

use Concursei\Modules\Extraction\EvidenceMatcher;
use PHPUnit\Framework\TestCase;

final class EvidenceMatcherTest extends TestCase
{
    private const DOC = "[[página 1]]\nEDITAL DE ABERTURA Nº 1/2027\nAs inscrições serão realizadas de 10/03/2027 a 09/04/2027, pelo site da banca.\n"
        . "[[página 2]]\nA prova objetiva será aplicada em 16 de maio de 2027, às 13h30, horário de Brasília.\n"
        . "Analista Judiciário – Área Judiciária | Nível Superior | 12 vagas + CR | R$ 13.994,78 | 40 horas\n"
        . "Taxa de inscrição: R$ 120,00 para cargos de nível superior. Requisito: diploma de curso supe-\nrior em Direito.";

    public function testEvidenceMustExistInDocumentIgnoringLayoutNoise(): void
    {
        $m = new EvidenceMatcher(self::DOC);
        self::assertTrue($m->found('as INSCRIÇÕES serão   realizadas de 10/03/2027'), 'Caixa e espaços não importam');
        self::assertTrue($m->found('Analista Judiciario - Area Judiciaria'), 'Acentos e travessão normalizados');
        self::assertTrue($m->found('diploma de curso superior em Direito'), 'Hifenização de quebra de linha desfeita');
        self::assertTrue($m->found('As inscrições serão realizadas... 09/04/2027'), 'Partes separadas por reticências, em ordem');
        self::assertFalse($m->found('09/04/2027 ... As inscrições serão realizadas'), 'Ordem invertida não vale');
        self::assertFalse($m->found('As inscrições serão realizadas de 01/03/2027'), 'Trecho inventado');
        self::assertFalse($m->found('prova'), 'Trecho curto demais não prova nada');
        self::assertFalse($m->found('[[página 2]] A prova objetiva'), 'Marcadores de página não fazem parte do texto');
    }

    public function testDatesMustBeSupportedByTheExcerpt(): void
    {
        $range = 'As inscrições serão realizadas de 10/03/2027 a 09/04/2027';
        self::assertTrue(EvidenceMatcher::dateIn('2027-03-10', 'day', $range));
        self::assertTrue(EvidenceMatcher::dateIn('2027-04-09', 'day', $range));
        self::assertFalse(EvidenceMatcher::dateIn('2027-10-03', 'day', $range), 'Dia e mês trocados');
        self::assertFalse(EvidenceMatcher::dateIn('2028-03-10', 'day', $range), 'Ano diferente');
        self::assertTrue(EvidenceMatcher::dateIn('2027-03-10', 'day', 'inscrições de 10 a 20/03/2027'), 'Início de intervalo abreviado');
        self::assertTrue(EvidenceMatcher::dateIn('2027-05-16', 'day', 'aplicada em 16 de maio de 2027'));
        self::assertTrue(EvidenceMatcher::dateIn('2027-03-01', 'day', 'a partir de 1º de março de 2027'));
        self::assertTrue(EvidenceMatcher::dateIn('2027-03-10', 'day', 'de 10 a 20 de março de 2027'));
        self::assertFalse(EvidenceMatcher::dateIn('2027-05-16', 'day', 'aplicada em 16 de maio'), 'Sem ano no trecho');
        self::assertFalse(EvidenceMatcher::dateIn('2027-05-06', 'day', 'aplicada em 16 de maio de 2027'), 'Dia 6 não é 16');
        self::assertTrue(EvidenceMatcher::dateIn('2027-06-01', 'month', 'prova prevista para junho de 2027'));
        self::assertTrue(EvidenceMatcher::dateIn('2027-06-01', 'month', 'prova prevista para 06/2027'));
        self::assertFalse(EvidenceMatcher::dateIn('2027-07-01', 'month', 'prova prevista para junho de 2027'));
        self::assertTrue(EvidenceMatcher::dateIn('2027-01-01', 'year', 'concurso previsto para 2027'));
    }

    public function testTimesMoneyNumbersLevelsAndReserve(): void
    {
        self::assertTrue(EvidenceMatcher::timeIn('13:30', 'às 13h30, horário de Brasília'));
        self::assertTrue(EvidenceMatcher::timeIn('08:00', 'portões abrem às 8h'));
        self::assertTrue(EvidenceMatcher::timeIn('13:00', 'início às 13:00'));
        self::assertFalse(EvidenceMatcher::timeIn('13:00', 'às 13h30'), '13h30 não é 13h');

        self::assertTrue(EvidenceMatcher::moneyIn(1399478, 'R$ 13.994,78'));
        self::assertTrue(EvidenceMatcher::moneyIn(12000, 'Taxa: R$ 120,00'));
        self::assertTrue(EvidenceMatcher::moneyIn(12000, 'Taxa: R$ 120'));
        self::assertFalse(EvidenceMatcher::moneyIn(12000, 'Taxa: R$ 1.120,00'), 'Valor contido em outro número não vale');

        self::assertTrue(EvidenceMatcher::numberIn(12, '12 vagas + CR'));
        self::assertTrue(EvidenceMatcher::numberIn(1500, '1.500 vagas'));
        self::assertFalse(EvidenceMatcher::numberIn(12, '112 vagas'));

        $row = 'Técnico Judiciário – Área Administrativa | Nível Médio | 30 vagas';
        self::assertTrue(EvidenceMatcher::levelIn('medio', 'Técnico Judiciário – Área Administrativa', $row));
        self::assertFalse(EvidenceMatcher::levelIn('tecnico', 'Técnico Judiciário – Área Administrativa', $row), 'O nome do cargo não prova nível técnico');
        self::assertTrue(EvidenceMatcher::levelIn('superior', 'Analista', 'Analista | diploma de graduação em Direito'));

        self::assertTrue(EvidenceMatcher::reserveIn('12 vagas + CR'));
        self::assertTrue(EvidenceMatcher::reserveIn('formação de cadastro de reserva'));
        self::assertFalse(EvidenceMatcher::reserveIn('12 vagas imediatas para a CRECHE'));
    }
}
