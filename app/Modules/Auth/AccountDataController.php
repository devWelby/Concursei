<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth;

use Concursei\Core\Clock;
use Concursei\Core\Config;
use Concursei\Core\Http\Controller;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Http\ValidationFailed;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;
use Concursei\Shared\Audit\AuditLog;
use Concursei\Shared\Auth\AuthUser;

/** "Meus dados": cópia dos dados pessoais e exclusão da conta, ambas confirmadas com a senha. */
final class AccountDataController extends Controller
{
    public function __construct(
        View $view,
        private AccountDataService $data,
        private AuthService $auth,
        private Session $session,
        private Config $config,
        private Clock $clock,
        private AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    public function show(Request $r): Response
    {
        return $this->render('account/data', ['title' => 'Meus dados', 'user' => $this->user($r)]);
    }

    public function export(Request $r): Response
    {
        $u = $this->user($r);
        $data = $this->validate($r, ['password' => 'required|string|max:256'], ['password' => 'a senha'], '/conta/dados');
        $this->checkPassword($r, $u, (string) $data['password'], 'password');
        $json = json_encode($this->data->export($u->id), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->audit->record($u->id, 'account.export', 'user', $u->id, [], $this->ip($r));
        return Response::download($json, 'concursei-meus-dados-' . $this->clock->now()->format('Y-m-d') . '.json', 'application/json; charset=utf-8');
    }

    public function delete(Request $r): Response
    {
        $u = $this->user($r);
        $data = $this->validate($r, ['delete_password' => 'required|string|max:256', 'confirm' => 'bool'], ['delete_password' => 'a senha', 'confirm' => 'a confirmação'], '/conta/dados');
        if ($data['confirm'] !== true) {
            throw new ValidationFailed(['confirm' => ['Marque a caixa para confirmar que entende que a exclusão é definitiva.']], '/conta/dados');
        }
        $this->checkPassword($r, $u, (string) $data['delete_password'], 'delete_password');
        if ($u->role !== 'student') {
            $this->session->flash('error', 'Contas da equipe não são excluídas por aqui. Um administrador deve primeiro mudar o papel para estudante (php bin/console user:role).');
            return Response::redirect('/conta/dados');
        }
        if ($this->data->delete($u->id)) {
            $this->audit->record($u->id, 'account.delete', 'user', $u->id, [], $this->ip($r));
        }
        $this->session->invalidate();
        $this->session->flash('success', 'Sua conta foi excluída. Os dados que identificavam você foram apagados ou anonimizados.');
        return Response::redirect('/');
    }

    private function checkPassword(Request $r, AuthUser $u, string $password, string $field): void
    {
        if (!$this->auth->verifyPassword($u->id, $password)) {
            $this->audit->record($u->id, 'account.data_bad_password', 'user', $u->id, [], $this->ip($r));
            throw new ValidationFailed([$field => ['Senha incorreta.']], '/conta/dados');
        }
    }

    private function user(Request $r): AuthUser
    {
        $u = $r->attribute('user');
        return $u instanceof AuthUser ? $u : throw HttpException::unauthorized();
    }

    private function ip(Request $r): string
    {
        return $r->clientIp($this->config->get('security.trusted_proxies', []));
    }
}
