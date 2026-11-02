<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Config;
use Concursei\Shared\Auth\AuthUser;
use DateTimeImmutable;

/**
 * Dados de DEMONSTRAÇÃO para ambiente local: órgãos e concursos fictícios,
 * marcados como tal, com links example.com e datas relativas a hoje (para
 * mostrar todas as fases). Nunca roda em produção/homologação.
 */
final class DemoSeeder
{
    public const PREFIX = 'demo-';

    public function __construct(
        private Config $config,
        private ReferenceRepository $refs,
        private ContestRepository $contests,
        private PublicationService $publication,
        private LocalDate $date,
    ) {
    }

    /** @return int concursos publicados (0 se a demonstração já existe) */
    public function run(AuthUser $staff): int
    {
        if (!in_array($this->config->string('app.env'), ['local', 'testing'], true)) {
            throw new \RuntimeException('Dados de demonstração só podem ser criados com APP_ENV=local ou testing.');
        }
        if (!$staff->can('contests.publish')) {
            throw new \RuntimeException('A conta informada precisa ser da equipe (editor ou admin).');
        }
        if ($this->contests->list(['q' => '[Demonstração]'], 1, 1)['total'] > 0) {
            return 0;
        }
        $today = $this->date->today();
        $d = static fn (string $mod): string => $today->modify($mod)->format('Y-m-d');

        $org = fn (string $slug, string $name, string $acronym, string $sphere, ?string $uf): int => $this->refs->save('organizations', null, [
            'slug' => self::PREFIX . $slug, 'name' => $name . ' (demonstração)', 'acronym' => $acronym, 'sphere' => $sphere, 'uf' => $uf,
            'website_url' => 'https://example.com/' . $slug,
        ]);
        $career = fn (string $slug, string $name, string $area, string $level): int => $this->refs->save('careers', null, [
            'slug' => self::PREFIX . $slug, 'name' => $name, 'area' => $area, 'education_level' => $level, 'description' => null,
        ]);
        $board = $this->refs->save('exam_boards', null, ['slug' => self::PREFIX . 'banca', 'name' => 'Banca Fictícia de Exemplo', 'acronym' => 'BFE', 'website_url' => 'https://example.com/banca']);
        $analista = $career('analista-judiciario', 'Analista Judiciário (exemplo)', 'tribunais', 'superior');
        $tecnico = $career('tecnico-judiciario', 'Técnico Judiciário (exemplo)', 'tribunais', 'medio');
        $soldado = $career('soldado', 'Soldado (exemplo)', 'policial', 'medio');
        $auditor = $career('auditor-fiscal', 'Auditor Fiscal (exemplo)', 'fiscal', 'superior');

        $specs = [
            [
                'slug' => 'tribunal-exemplo', 'org' => $org('tribunal', 'Tribunal Fictício de Exemplo', 'TFE', 'federal', 'SP'), 'board' => $board,
                'title' => '[Demonstração] Tribunal Fictício de Exemplo — Analista e Técnico', 'uf' => 'SP', 'status' => 'edital_published', 'fee' => 9500,
                'offers' => [
                    ['career_id' => $analista, 'title' => 'Analista Judiciário — Área Judiciária', 'education_level' => 'superior', 'vacancies' => 12, 'reserve' => 1, 'salary_cents' => 1350000],
                    ['career_id' => $tecnico, 'title' => 'Técnico Judiciário — Área Administrativa', 'education_level' => 'medio', 'vacancies' => null, 'reserve' => 1, 'salary_cents' => 820000],
                ],
                'events' => [['edital', $d('-12 days'), 'day'], ['registration_start', $d('-5 days'), 'day'], ['registration_end', $d('+20 days'), 'day'], ['exam', $d('+60 days'), 'day', '13:00:00']],
            ],
            [
                'slug' => 'policia-exemplo', 'org' => $org('policia', 'Polícia Fictícia de Exemplo', 'PFE', 'estadual', 'RJ'), 'board' => $board,
                'title' => '[Demonstração] Polícia Fictícia de Exemplo — Soldado', 'uf' => 'RJ', 'status' => 'edital_published', 'fee' => 6000,
                'offers' => [['career_id' => $soldado, 'title' => 'Soldado', 'education_level' => 'medio', 'vacancies' => 800, 'reserve' => 0, 'salary_cents' => 450000]],
                'events' => [['edital', $d('-2 days'), 'day'], ['registration_start', $d('+10 days'), 'day'], ['registration_end', $d('+40 days'), 'day'], ['exam', $d('+3 months'), 'month']],
            ],
            [
                'slug' => 'fazenda-exemplo', 'org' => $org('fazenda', 'Secretaria Fictícia da Fazenda', 'SFF', 'estadual', 'MG'), 'board' => null,
                'title' => '[Demonstração] Secretaria Fictícia da Fazenda — Auditor', 'uf' => 'MG', 'status' => 'authorized', 'fee' => null,
                'offers' => [['career_id' => $auditor, 'title' => 'Auditor Fiscal', 'education_level' => 'superior', 'vacancies' => 50, 'reserve' => 0, 'salary_cents' => null]],
                'events' => [['authorization', $d('-30 days'), 'day'], ['edital', $d('+6 months'), 'year'], ['exam', null, 'unknown']],
            ],
            [
                'slug' => 'instituto-exemplo', 'org' => $org('instituto', 'Instituto Nacional Fictício', 'INF', 'federal', null), 'board' => $board,
                'title' => '[Demonstração] Instituto Nacional Fictício — Técnico', 'uf' => null, 'status' => 'edital_published', 'fee' => 8500,
                'offers' => [['career_id' => null, 'title' => 'Técnico do Seguro (exemplo)', 'education_level' => 'medio', 'vacancies' => 1000, 'reserve' => 1, 'salary_cents' => 580000]],
                'events' => [['registration_start', $d('-60 days'), 'day'], ['registration_end', $d('-40 days'), 'day'], ['exam', $d('-10 days'), 'day'], ['answer_key', $d('+2 days'), 'day']],
            ],
        ];

        foreach ($specs as $spec) {
            $id = $this->contests->create(self::PREFIX . $spec['slug'], [
                'organization_id' => $spec['org'], 'exam_board_id' => $spec['board'], 'title' => $spec['title'],
                'edition_year' => (int) $today->format('Y'), 'uf' => $spec['uf'], 'status' => $spec['status'],
                'summary' => "Concurso FICTÍCIO criado para demonstração do Concursei.\n\nNenhuma informação desta página corresponde a um concurso real.",
                'fee_cents' => $spec['fee'], 'fee_note' => null, 'official_url' => 'https://example.com/' . $spec['slug'],
                'registration_url' => null, 'source_url' => 'https://example.com/' . $spec['slug'] . '/edital.pdf', 'source_published_on' => $d('-12 days'),
            ], $staff->id);
            foreach ($spec['offers'] as $i => $o) {
                $this->contests->saveOffer($id, $this->version($id), null, $o + ['salary_note' => null, 'workload_hours' => 40, 'requirements' => null, 'location' => null, 'sort_order' => $i], $staff->id);
            }
            foreach ($spec['events'] as $e) {
                [$date, $precision] = self::normalize($e[1], $e[2]);
                $this->contests->saveEvent($id, $this->version($id), null, [
                    'type' => $e[0], 'label' => null, 'date_value' => $date, 'date_precision' => $precision, 'time_local' => $e[3] ?? null, 'note' => null,
                ], $staff->id);
            }
            $this->publication->submit($id, $this->version($id), $staff);
            $this->publication->publish($id, $this->version($id), $staff, 'Dados fictícios de demonstração', false, true);
        }
        return count($specs);
    }

    private function version(int $id): int
    {
        return (int) ($this->contests->find($id)['version'] ?? 0);
    }

    /** Mês/ano guardam o primeiro dia do período (convenção do schema). @return array{0:?string,1:string} */
    private static function normalize(?string $date, string $precision): array
    {
        if ($date === null) {
            return [null, 'unknown'];
        }
        $d = new DateTimeImmutable($date);
        return match ($precision) {
            'month' => [$d->format('Y-m-01'), 'month'],
            'year' => [$d->format('Y-01-01'), 'year'],
            default => [$date, 'day'],
        };
    }
}
