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

final class AuthController extends Controller
{
    private const MFA_PENDING_SECONDS = 300;
    private const MFA_MAX_ATTEMPTS = 5;

    public function __construct(
        View $view,
        private AuthService $auth,
        private MfaService $mfa,
        private Session $session,
        private Config $config,
        private AuditLog $audit,
        private Clock $clock,
    ) {
        parent::__construct($view);
    }

    private function ip(Request $r): string
    {
        return $r->clientIp($this->config->get('security.trusted_proxies', []));
    }

    private function redirectAfterLogin(): Response
    {
        $intended = $this->session->get('intended');
        $this->session->forget('intended');
        return Response::redirect(is_string($intended) ? $intended : $this->config->string('app.home_after_login', '/conta'));
    }

    // ---- Cadastro ----

    public function showRegister(Request $r): Response
    {
        return $this->render('auth/register', ['title' => 'Criar conta', 'mailAvailable' => $this->auth->mailAvailable()]);
    }

    public function register(Request $r): Response
    {
        if (!$this->auth->mailAvailable()) {
            $this->session->flash('error', 'Cadastro temporariamente indisponível: o envio de e-mails de confirmação não está configurado.');
            return Response::redirect('/cadastro');
        }
        $data = $this->validate($r, [
            'display_name' => 'required|string|min:2|max:80',
            'email' => 'required|email|max:254',
            'password' => 'required|string|password|confirmed',
            'accept_terms' => 'required|bool',
            'age_18' => 'required|bool',
            'marketing' => 'bool',
        ], ['display_name' => 'seu nome', 'email' => 'o e-mail', 'password' => 'a senha', 'accept_terms' => 'o aceite dos termos', 'age_18' => 'a confirmação de idade'], '/cadastro');
        if ($data['accept_terms'] !== true || $data['age_18'] !== true) {
            throw new ValidationFailed(['accept_terms' => ['É necessário aceitar os termos e confirmar ter 18 anos ou mais.']], '/cadastro', $r->only(['display_name', 'email']));
        }
        $this->auth->register([
            'display_name' => (string) $data['display_name'],
            'email' => (string) $data['email'],
            'password' => (string) $data['password'],
            'marketing' => (bool) $data['marketing'],
        ], $this->ip($r));
        $this->session->set('check_email', true);
        return Response::redirect('/cadastro/verifique-email');
    }

    public function checkEmail(Request $r): Response
    {
        return $this->render('auth/check-email', ['title' => 'Confirme seu e-mail', 'noindex' => true]);
    }

    public function resendVerification(Request $r): Response
    {
        $data = $this->validate($r, ['email' => 'required|email|max:254'], ['email' => 'o e-mail'], '/cadastro/verifique-email');
        $this->auth->resendVerification((string) $data['email']);
        $this->session->flash('info', 'Se houver um cadastro pendente de confirmação para esse e-mail, enviamos um novo link.');
        return Response::redirect('/cadastro/verifique-email');
    }

    public function showVerify(Request $r): Response
    {
        $token = is_string($r->query('token')) ? $r->query('token') : '';
        return $this->render('auth/verify', ['title' => 'Confirmar e-mail', 'token' => $token]);
    }

    /** POST (não GET) para que pré-carregamento de links não consuma o token. */
    public function verify(Request $r): Response
    {
        $userId = $this->auth->verifyEmail($r->string('token'), $this->ip($r));
        if ($userId === null) {
            $this->session->flash('error', 'Link de confirmação inválido ou expirado. Solicite um novo.');
            return Response::redirect('/cadastro/verifique-email');
        }
        $this->session->flash('success', 'E-mail confirmado. Agora é só entrar.');
        return Response::redirect('/entrar');
    }

    // ---- Login ----

    public function showLogin(Request $r): Response
    {
        return $this->render('auth/login', ['title' => 'Entrar']);
    }

    public function login(Request $r): Response
    {
        $data = $this->validate($r, ['email' => 'required|email|max:254', 'password' => 'required|string|max:256'], ['email' => 'o e-mail', 'password' => 'a senha'], '/entrar');
        $result = $this->auth->attemptLogin((string) $data['email'], (string) $data['password'], $this->ip($r));
        switch ($result['status']) {
            case 'throttled':
                throw HttpException::tooManyRequests($result['retry_after'] ?? 900);
            case 'invalid':
                throw new ValidationFailed(['password' => ['E-mail ou senha incorretos.']], '/entrar', ['email' => $data['email']], 'E-mail ou senha incorretos.');
            case 'unverified':
                $this->session->flash('warning', 'Confirme seu e-mail antes de entrar. Você pode pedir um novo link abaixo.');
                return Response::redirect('/cadastro/verifique-email');
            case 'mfa':
                $this->session->regenerate();
                $this->session->set('mfa_pending', ['uid' => $result['user']->id, 'at' => $this->clock->now()->getTimestamp(), 'tries' => 0]);
                return Response::redirect('/entrar/verificacao');
            default:
                $this->session->login($result['user']->id);
                return $this->redirectAfterLogin();
        }
    }

    /** @return array{uid:int,at:int,tries:int}|null */
    private function pendingMfa(): ?array
    {
        $p = $this->session->get('mfa_pending');
        if (!is_array($p) || !isset($p['uid'], $p['at']) || $this->clock->now()->getTimestamp() - (int) $p['at'] > self::MFA_PENDING_SECONDS) {
            $this->session->forget('mfa_pending');
            return null;
        }
        return ['uid' => (int) $p['uid'], 'at' => (int) $p['at'], 'tries' => (int) ($p['tries'] ?? 0)];
    }

    public function showMfa(Request $r): Response
    {
        if ($this->pendingMfa() === null) {
            return Response::redirect('/entrar');
        }
        return $this->render('auth/mfa', ['title' => 'Verificação em duas etapas']);
    }

    public function verifyMfa(Request $r): Response
    {
        $pending = $this->pendingMfa();
        if ($pending === null) {
            $this->session->flash('error', 'A verificação expirou. Entre novamente.');
            return Response::redirect('/entrar');
        }
        $data = $this->validate($r, ['code' => 'required|string|max:20'], ['code' => 'o código'], '/entrar/verificacao');
        if ($this->mfa->lockedOut($pending['uid'])) {
            $this->session->forget('mfa_pending');
            $this->session->flash('error', 'Muitas tentativas de código nesta conta. Aguarde 15 minutos e entre novamente.');
            return Response::redirect('/entrar');
        }
        if (!$this->mfa->verifyForUser($pending['uid'], (string) $data['code'])) {
            $pending['tries']++;
            $this->audit->record(null, 'auth.mfa_failed', 'user', $pending['uid'], [], $this->ip($r));
            if ($pending['tries'] >= self::MFA_MAX_ATTEMPTS) {
                $this->session->forget('mfa_pending');
                $this->session->flash('error', 'Muitas tentativas. Entre novamente.');
                return Response::redirect('/entrar');
            }
            $this->session->set('mfa_pending', $pending);
            throw new ValidationFailed(['code' => ['Código inválido ou já utilizado.']], '/entrar/verificacao');
        }
        $this->session->forget('mfa_pending');
        $this->session->login($pending['uid']);
        $this->auth->completeLogin($pending['uid'], true, $this->ip($r));
        return $this->redirectAfterLogin();
    }

    public function logout(Request $r): Response
    {
        $user = $r->attribute('user');
        if ($user instanceof AuthUser) {
            $this->audit->record($user->id, 'auth.logout', 'user', $user->id, [], $this->ip($r));
        }
        $this->session->invalidate();
        $this->session->flash('success', 'Você saiu da sua conta.');
        return Response::redirect('/');
    }
}
