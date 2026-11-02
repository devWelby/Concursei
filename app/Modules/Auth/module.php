<?php

declare(strict_types=1);

use Concursei\Core\App;
use Concursei\Core\Config;
use Concursei\Core\Console\Console;
use Concursei\Core\Container;
use Concursei\Core\Validation\PasswordPolicy;
use Concursei\Modules\Auth\AccountController;
use Concursei\Modules\Auth\AccountDataController;
use Concursei\Modules\Auth\AccountDataService;
use Concursei\Modules\Auth\AuthController;
use Concursei\Modules\Auth\Middleware\LoadCurrentUser;
use Concursei\Modules\Auth\Middleware\RequireAuth;
use Concursei\Modules\Auth\Middleware\RequireGuest;
use Concursei\Modules\Auth\Middleware\RequirePermission;
use Concursei\Modules\Auth\MfaService;
use Concursei\Modules\Auth\PasswordController;
use Concursei\Modules\Auth\PasswordHasher;
use Concursei\Modules\Auth\TokenService;
use Concursei\Modules\Auth\UserRepository;
use Concursei\Shared\Audit\AuditLog;

return static function (App $app, Container $c, Config $config): void {
    $app->aliasMiddleware('user', LoadCurrentUser::class);
    $app->aliasMiddleware('auth', RequireAuth::class);
    $app->aliasMiddleware('guest', RequireGuest::class);
    $app->aliasMiddleware('can', RequirePermission::class);
    // Carrega o usuário (do banco) em toda requisição, após sessão e CSRF.
    $app->appendGlobalMiddleware('user');

    $r = $app->router();

    // Cadastro e confirmação de e-mail
    $r->get('/cadastro', [AuthController::class, 'showRegister'], ['name' => 'register', 'middleware' => ['guest']]);
    $r->post('/cadastro', [AuthController::class, 'register'], ['middleware' => ['guest', 'throttle:register']]);
    $r->get('/cadastro/verifique-email', [AuthController::class, 'checkEmail'], ['name' => 'register.check']);
    $r->post('/cadastro/reenviar', [AuthController::class, 'resendVerification'], ['middleware' => ['throttle:verify_email']]);
    $r->get('/confirmar-email', [AuthController::class, 'showVerify'], ['name' => 'verify']);
    $r->post('/confirmar-email', [AuthController::class, 'verify'], ['middleware' => ['throttle:verify_email']]);

    // Login (+ segunda etapa) e logout
    $r->get('/entrar', [AuthController::class, 'showLogin'], ['name' => 'login', 'middleware' => ['guest']]);
    $r->post('/entrar', [AuthController::class, 'login'], ['middleware' => ['guest', 'throttle:login']]);
    $r->get('/entrar/verificacao', [AuthController::class, 'showMfa'], ['name' => 'login.mfa', 'middleware' => ['guest']]);
    $r->post('/entrar/verificacao', [AuthController::class, 'verifyMfa'], ['middleware' => ['guest', 'throttle:mfa']]);
    $r->post('/sair', [AuthController::class, 'logout'], ['name' => 'logout']);

    // Recuperação de senha
    $r->get('/recuperar-senha', [PasswordController::class, 'showForgot'], ['name' => 'password.forgot', 'middleware' => ['guest']]);
    $r->post('/recuperar-senha', [PasswordController::class, 'forgot'], ['middleware' => ['guest', 'throttle:password_reset']]);
    $r->get('/redefinir-senha', [PasswordController::class, 'showReset'], ['name' => 'password.reset']);
    $r->post('/redefinir-senha', [PasswordController::class, 'reset'], ['middleware' => ['throttle:password_reset']]);

    // Conta
    $r->get('/conta', [AccountController::class, 'index'], ['name' => 'account', 'middleware' => ['auth']]);
    $r->post('/conta/perfil', [AccountController::class, 'updateProfile'], ['middleware' => ['auth']]);
    $r->post('/conta/preferencias', [AccountController::class, 'updatePreferences'], ['middleware' => ['auth']]);
    $r->post('/conta/senha', [AccountController::class, 'changePassword'], ['middleware' => ['auth', 'throttle:login']]);
    $r->post('/conta/email', [AccountController::class, 'changeEmail'], ['middleware' => ['auth', 'throttle:verify_email']]);
    // Confirmação do novo e-mail não exige login (o link pode ser aberto em outro aparelho).
    $r->get('/conta/email/confirmar', [AccountController::class, 'showConfirmEmail'], ['name' => 'account.email.confirm']);
    $r->post('/conta/email/confirmar', [AccountController::class, 'confirmEmail'], ['middleware' => ['throttle:verify_email']]);
    $r->get('/conta/seguranca', [AccountController::class, 'showSecurity'], ['name' => 'account.security', 'middleware' => ['auth']]);
    $r->post('/conta/seguranca/ativar', [AccountController::class, 'enableMfa'], ['middleware' => ['auth', 'throttle:mfa']]);
    $r->post('/conta/seguranca/desativar', [AccountController::class, 'disableMfa'], ['middleware' => ['auth', 'throttle:mfa']]);
    $r->post('/conta/seguranca/codigos', [AccountController::class, 'regenerateCodes'], ['middleware' => ['auth', 'throttle:mfa']]);
    // Direitos do titular: cópia dos dados e exclusão da conta (confirmadas com a senha).
    $r->get('/conta/dados', [AccountDataController::class, 'show'], ['name' => 'account.data', 'middleware' => ['auth']]);
    $r->post('/conta/dados/exportar', [AccountDataController::class, 'export'], ['middleware' => ['auth', 'throttle:export']]);
    $r->post('/conta/excluir', [AccountDataController::class, 'delete'], ['middleware' => ['auth', 'throttle:login']]);

    $app->registerConsole(static function (Console $console) use ($c): void {
        $findUser = static function (Console $io, string $email) use ($c): ?array {
            $row = $c->get(UserRepository::class)->findByEmail(mb_strtolower(trim($email)));
            if ($row === null) {
                $io->error('Usuário não encontrado.');
            }
            return $row;
        };

        $console->register(
            'user:create-admin',
            'Cria conta da equipe: --email=... --name="..." [--role=admin|editor]. Senha lida do STDIN (nunca por argumento).',
            static function (array $o, array $a, Console $io) use ($c): int {
                $email = mb_strtolower(trim((string) ($o['email'] ?? '')));
                $name = trim((string) ($o['name'] ?? ''));
                $role = (string) ($o['role'] ?? 'admin');
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
                    $io->error('Informe um e-mail válido em --email.');
                    return 1;
                }
                if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
                    $io->error('Informe o nome em --name (2 a 80 caracteres).');
                    return 1;
                }
                if (!in_array($role, ['admin', 'editor'], true)) {
                    $io->error('--role deve ser admin ou editor.');
                    return 1;
                }
                $users = $c->get(UserRepository::class);
                if ($users->findByEmail($email) !== null) {
                    $io->error('Já existe conta com esse e-mail. Use user:role para alterar o papel.');
                    return 1;
                }
                $password = $io->ask('Senha:', true);
                $error = PasswordPolicy::check($password);
                if ($error !== null) {
                    $io->error($error);
                    return 1;
                }
                if ($io->ask('Repita a senha:', true) !== $password) {
                    $io->error('As senhas não conferem.');
                    return 1;
                }
                $id = $users->create($email, $c->get(PasswordHasher::class)->hash($password), $name, $role, true);
                $c->get(AuditLog::class)->record(null, 'user.staff_created_cli', 'user', $id, ['role' => $role]);
                $io->line("Conta criada (id {$id}, papel {$role}). No primeiro acesso, ative a verificação em duas etapas em /conta/seguranca — sem ela a administração fica bloqueada.");
                return 0;
            }
        );

        $console->register('user:role', 'Altera o papel: <email> <student|editor|admin>', static function (array $o, array $a, Console $io) use ($c, $findUser): int {
            if (count($a) !== 2 || !in_array($a[1], ['student', 'editor', 'admin'], true)) {
                $io->error('Uso: user:role <email> <student|editor|admin>');
                return 1;
            }
            $row = $findUser($io, $a[0]);
            if ($row === null) {
                return 1;
            }
            $c->get(UserRepository::class)->setRole((int) $row['id'], $a[1]);
            $c->get(AuditLog::class)->record(null, 'user.role_changed_cli', 'user', (int) $row['id'], ['from' => $row['role'], 'to' => $a[1]]);
            $io->line("Papel alterado: {$row['role']} → {$a[1]}.");
            return 0;
        });

        $console->register('user:mfa-reset', 'Desativa o MFA de uma conta (aparelho perdido, após verificar identidade): <email> --confirm', static function (array $o, array $a, Console $io) use ($c, $findUser): int {
            if (count($a) !== 1 || !isset($o['confirm'])) {
                $io->error('Uso: user:mfa-reset <email> --confirm');
                return 1;
            }
            $row = $findUser($io, $a[0]);
            if ($row === null) {
                return 1;
            }
            $c->get(MfaService::class)->disable((int) $row['id']);
            $c->get(AuditLog::class)->record(null, 'auth.mfa_reset_cli', 'user', (int) $row['id']);
            $io->line('MFA desativado. A pessoa deve reativá-lo no próximo acesso.');
            return 0;
        });

        $console->register('user:delete', 'Exclui (anonimiza) a conta de estudante a pedido do titular, após verificar a identidade: <email> --confirm', static function (array $o, array $a, Console $io) use ($c, $findUser): int {
            if (count($a) !== 1 || !isset($o['confirm'])) {
                $io->error('Uso: user:delete <email> --confirm');
                return 1;
            }
            $row = $findUser($io, $a[0]);
            if ($row === null) {
                return 1;
            }
            if ($row['role'] !== 'student') {
                $io->error('Conta da equipe: mude o papel para student (user:role) antes de excluir.');
                return 1;
            }
            $c->get(AccountDataService::class)->delete((int) $row['id']);
            $c->get(AuditLog::class)->record(null, 'account.delete_cli', 'user', (int) $row['id']);
            $io->line('Conta excluída: identificação anonimizada, sessões, favoritos e links pendentes apagados.');
            return 0;
        });

        $console->register('tokens:purge', 'Remove tokens de e-mail/senha expirados há mais de 24 h (Cron).', static function (array $o, array $a, Console $io) use ($c): int {
            $io->line('Tokens removidos: ' . $c->get(TokenService::class)->purgeExpired());
            return 0;
        });
    });
};
