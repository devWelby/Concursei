<?php

declare(strict_types=1);

namespace Concursei\Tests\Support;

use Concursei\Modules\Catalog\ContestRepository;
use Concursei\Modules\Catalog\PublicationService;
use Concursei\Modules\Catalog\ReferenceRepository;
use Concursei\Modules\Auth\UserRepository;
use Concursei\Shared\Auth\AuthUser;

/** Dados fictícios de catálogo para testes de integração (domínios example.com). */
trait InteractsWithCatalog
{
    protected function refs(): ReferenceRepository
    {
        return $this->app->container->get(ReferenceRepository::class);
    }

    protected function contests(): ContestRepository
    {
        return $this->app->container->get(ContestRepository::class);
    }

    protected function publication(): PublicationService
    {
        return $this->app->container->get(PublicationService::class);
    }

    protected function staff(string $email = 'editora@example.com', string $role = 'editor'): AuthUser
    {
        $id = $this->createUser($email, $role);
        $this->db->run('UPDATE users SET mfa_enabled_at = ? WHERE id = ?', [$this->clock->nowDb(), $id]);
        return AuthUser::fromRow($this->app->container->get(UserRepository::class)->findById($id));
    }

    protected function organization(string $slug = 'tribunal-exemplo', ?string $uf = 'SP'): int
    {
        return $this->refs()->save('organizations', null, ['slug' => $slug, 'name' => 'Tribunal Exemplo ' . $slug, 'acronym' => strtoupper(substr($slug, 0, 5)), 'sphere' => 'estadual', 'uf' => $uf, 'website_url' => null]);
    }

    protected function board(string $slug = 'banca-exemplo'): int
    {
        return $this->refs()->save('exam_boards', null, ['slug' => $slug, 'name' => 'Banca Exemplo', 'acronym' => 'BEX', 'website_url' => null]);
    }

    protected function career(string $slug = 'analista-exemplo', string $area = 'tribunais', string $level = 'superior'): int
    {
        return $this->refs()->save('careers', null, ['slug' => $slug, 'name' => 'Analista Exemplo', 'area' => $area, 'education_level' => $level, 'description' => null]);
    }

    /** @param array<string,mixed> $data */
    protected function contest(int $userId, array $data = [], ?string $slug = null): int
    {
        $slug ??= 'concurso-exemplo-2027';
        $data['organization_id'] ??= $this->organization('orgao-' . $slug);
        $data += [
            'exam_board_id' => null,
            'title' => 'Concurso Exemplo 2027',
            'edition_year' => 2027,
            'uf' => 'SP',
            'status' => 'edital_published',
            'summary' => null,
            'fee_cents' => 9000,
            'fee_note' => null,
            'official_url' => 'https://example.com/concurso',
            'registration_url' => null,
            'source_url' => 'https://example.com/edital.pdf',
            'source_published_on' => '2027-01-20',
        ];
        return $this->contests()->create($slug, $data, $userId);
    }

    protected function version(int $contestId): int
    {
        return (int) $this->db->scalar('SELECT version FROM contests WHERE id = ?', [$contestId]);
    }

    /** @param array<string,mixed> $data */
    protected function offer(int $contestId, int $userId, array $data = []): void
    {
        $data += ['career_id' => null, 'title' => 'Analista Judiciário', 'education_level' => 'superior', 'vacancies' => 10, 'reserve' => 1,
            'salary_cents' => 1350000, 'salary_note' => null, 'workload_hours' => 40, 'requirements' => null, 'location' => null, 'sort_order' => 0];
        $this->contests()->saveOffer($contestId, $this->version($contestId), null, $data, $userId);
    }

    protected function event(int $contestId, int $userId, string $type, ?string $date, string $precision = 'day', ?string $time = null): void
    {
        $this->contests()->saveEvent($contestId, $this->version($contestId), null, [
            'type' => $type, 'label' => null, 'date_value' => $date, 'date_precision' => $precision, 'time_local' => $time, 'note' => null,
        ], $userId);
    }

    /** Envia para revisão e publica (mesma pessoa; revisão em duas pessoas desligada por padrão). */
    protected function publish(int $contestId, AuthUser $user, string $note = 'Publicação inicial conferida com o edital'): int
    {
        $this->publication()->submit($contestId, $this->version($contestId), $user);
        return $this->publication()->publish($contestId, $this->version($contestId), $user, $note, false, true);
    }

    /** Concurso publicado com inscrições 01–31/03/2027 e prova em 10/05/2027. */
    protected function publishedContest(AuthUser $user, string $slug = 'concurso-exemplo-2027', array $data = []): int
    {
        $id = $this->contest($user->id, $data, $slug);
        $this->offer($id, $user->id, ['career_id' => $this->career('analista-' . $slug)]);
        $this->event($id, $user->id, 'registration_start', '2027-03-01');
        $this->event($id, $user->id, 'registration_end', '2027-03-31');
        $this->event($id, $user->id, 'exam', '2027-05-10', 'day', '13:00:00');
        $this->publish($id, $user);
        return $id;
    }
}
