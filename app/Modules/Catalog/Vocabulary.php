<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

/**
 * Vocabulário fixo do catálogo (rótulos pt-BR). Valores aceitos em filtros e
 * formulários vêm SEMPRE destas listas (allowlist).
 */
final class Vocabulary
{
    public const UFS = [
        'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará',
        'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
        'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina', 'SP' => 'São Paulo',
        'SE' => 'Sergipe', 'TO' => 'Tocantins',
    ];

    public const REGIONS = [
        'norte' => ['label' => 'Norte', 'ufs' => ['AC', 'AP', 'AM', 'PA', 'RO', 'RR', 'TO']],
        'nordeste' => ['label' => 'Nordeste', 'ufs' => ['AL', 'BA', 'CE', 'MA', 'PB', 'PE', 'PI', 'RN', 'SE']],
        'centro-oeste' => ['label' => 'Centro-Oeste', 'ufs' => ['DF', 'GO', 'MT', 'MS']],
        'sudeste' => ['label' => 'Sudeste', 'ufs' => ['ES', 'MG', 'RJ', 'SP']],
        'sul' => ['label' => 'Sul', 'ufs' => ['PR', 'RS', 'SC']],
    ];

    public const AREAS = [
        'administrativa' => 'Administrativa',
        'tribunais' => 'Tribunais e Judiciário',
        'policial' => 'Policial e segurança pública',
        'fiscal' => 'Fiscal',
        'controle' => 'Controle e auditoria',
        'legislativa' => 'Legislativa',
        'bancaria' => 'Bancária',
        'saude' => 'Saúde',
        'educacao' => 'Educação',
        'tecnologia' => 'Tecnologia da informação',
        'juridica' => 'Carreiras jurídicas',
        'militar' => 'Militar',
        'outras' => 'Outras',
    ];

    public const EDUCATION_LEVELS = [
        'fundamental' => 'Fundamental',
        'medio' => 'Médio',
        'tecnico' => 'Técnico',
        'superior' => 'Superior',
    ];

    public const SPHERES = [
        'federal' => 'Federal',
        'estadual' => 'Estadual',
        'municipal' => 'Municipal',
        'distrital' => 'Distrital',
    ];

    /** Situação informada pela equipe (base para a fase derivada). */
    public const STATUSES = [
        'predicted' => 'Previsto',
        'authorized' => 'Autorizado',
        'board_defined' => 'Banca definida',
        'edital_published' => 'Edital publicado',
        'suspended' => 'Suspenso',
        'cancelled' => 'Cancelado',
        'concluded' => 'Concluído',
    ];

    /** Fase exibida ao público (derivada de situação + datas com precisão de dia). */
    public const PHASES = [
        'predicted' => 'Previsto',
        'authorized' => 'Autorizado',
        'board_defined' => 'Banca definida',
        'edital_published' => 'Edital publicado',
        'registration_open' => 'Inscrições abertas',
        'registration_closed' => 'Inscrições encerradas',
        'exam_done' => 'Prova realizada',
        'answer_key' => 'Gabarito divulgado',
        'result' => 'Resultado divulgado',
        'suspended' => 'Suspenso',
        'cancelled' => 'Cancelado',
        'concluded' => 'Concluído',
    ];

    public const EVENT_TYPES = [
        'authorization' => 'Autorização',
        'board_defined' => 'Definição da banca',
        'edital' => 'Publicação do edital',
        'registration_start' => 'Início das inscrições',
        'registration_end' => 'Fim das inscrições',
        'fee_exemption_deadline' => 'Prazo de isenção da taxa',
        'exam' => 'Prova',
        'answer_key' => 'Gabarito',
        'result' => 'Resultado',
        'other' => 'Outro',
    ];

    public const DATE_PRECISIONS = [
        'day' => 'Dia exato',
        'month' => 'Somente mês',
        'year' => 'Somente ano',
        'unknown' => 'A definir',
    ];

    /**
     * Situações do filtro público (agrupam fases). Sem filtro, a listagem
     * esconde encerrados e cancelados.
     */
    public const SITUATIONS = [
        'abertos' => ['label' => 'Inscrições abertas', 'phases' => ['registration_open']],
        'edital' => ['label' => 'Edital publicado, inscrições não iniciadas', 'phases' => ['edital_published']],
        'previstos' => ['label' => 'Previstos, autorizados ou com banca definida', 'phases' => ['predicted', 'authorized', 'board_defined']],
        'andamento' => ['label' => 'Inscrições encerradas, em andamento', 'phases' => ['registration_closed', 'exam_done', 'answer_key', 'result']],
        'suspensos' => ['label' => 'Suspensos', 'phases' => ['suspended']],
        'encerrados' => ['label' => 'Concluídos ou cancelados', 'phases' => ['concluded', 'cancelled']],
    ];

    public const SORTS = [
        'recentes' => 'Atualizados recentemente',
        'inscricoes' => 'Inscrições terminando antes',
        'proximos' => 'Próximas datas',
        'vagas' => 'Mais vagas',
        'salario' => 'Maior salário',
    ];

    public static function regionOf(?string $uf): ?string
    {
        if ($uf === null) {
            return null;
        }
        foreach (self::REGIONS as $slug => $r) {
            if (in_array($uf, $r['ufs'], true)) {
                return $slug;
            }
        }
        return null;
    }

    /** Lista "a,b,c" para a regra de validação in:. @param array<string,mixed> $map */
    public static function keys(array $map): string
    {
        return implode(',', array_keys($map));
    }

    public static function label(array $map, ?string $key, string $fallback = '—'): string
    {
        return $key !== null && isset($map[$key]) ? (is_array($map[$key]) ? (string) $map[$key]['label'] : (string) $map[$key]) : $fallback;
    }
}
