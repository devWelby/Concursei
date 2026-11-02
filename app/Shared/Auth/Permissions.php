<?php

declare(strict_types=1);

namespace Concursei\Shared\Auth;

/**
 * Mapa papel → permissões (D-005). Verificado no servidor em cada ação.
 * Editor NÃO gerencia usuários, segredos, orçamento de IA nem configurações.
 */
final class Permissions
{
    private const STUDENT = ['account.manage', 'study.use', 'favorites.manage', 'notes.manage', 'exams.request', 'reports.create'];

    private const EDITOR = [
        'admin.access', 'contests.edit', 'contests.review', 'contests.publish', 'catalog.edit', 'content.edit', 'content.publish',
        'sources.manage', 'questions.review', 'reports.review', 'corrections.publish',
    ];

    private const ADMIN = ['users.manage', 'settings.manage', 'ai.budget.manage', 'jobs.manage', 'audit.view', 'autopublish.manage', 'monetization.manage'];

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        return match ($role) {
            'student' => self::STUDENT,
            'editor' => array_merge(self::STUDENT, self::EDITOR),
            'admin' => array_merge(self::STUDENT, self::EDITOR, self::ADMIN),
            default => [],
        };
    }

    public static function allows(string $role, string $permission): bool
    {
        return in_array($permission, self::forRole($role), true);
    }
}
