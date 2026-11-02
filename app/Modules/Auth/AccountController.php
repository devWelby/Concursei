<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth;

use Concursei\Core\Config;
use Concursei\Core\Http\Controller;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Http\ValidationFailed;
use Concursei\Core\Security\Crypto;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;
use Concursei\Shared\Audit\AuditLog;
use Concursei\Shared\Auth\AuthUser;
use Concursei\Shared\Privacy\ConsentService;

final class AccountController extends Controller
{
    public const TIMEZONES = [
        'America/Sao_Paulo', 'America/Bahia', 'America/Fortaleza', 'America/Recife', 'America/Maceio', 'America/Belem',
        'America/Araguaina', 'America/Santarem', 'America/Manaus', 'America/Cuiaba', 'America/Campo_Grande',
        'America/Porto_Velho', 'America/Boa_Vista', 'America/Rio_Branco', 'America/Eirunepe', 'America/Noronha',
    ];

    public function __construct(
        View $view,
        private AuthService $auth,
        private MfaService $mfa,
        private UserRepository $users,
        private Session $session,
        private Config $config,
        private Crypto $crypto,
        private AuditLog $audit,
        private ConsentService $consents,
    ) {
        parent::__construct($view);
    }

    private function user(Request $r): AuthUser
    {
        $u = $r->attribute('user');
        if (!$u instanceof AuthUser) {
            throw HttpException::unauthorized();
        }
        return $u;
    }

    private function ip(Request $r): string
    {
        return $r->clientIp($this->config->get('security.trusted_proxies', []));
    }

    public function index(Request $r): Response
    {
        $u = $this->user($r);
        return $this->render('account/index', [
            'title' => 'Minha conta',
            'user' => $u,
            'timezones' => self::TIMEZONES,
            'consents' => $this->consents->currentForUser($u->id),
        ]);
    }

    public function updateProfile(Request $r): Response
    {
        $u = $this->user($r);
        $data = $this->validate($r, [
            'display_name' => 'required|string|min:2|max:80',
            'timezone' => 'required|in:' . implode(',', self::TIMEZONES),
        ], ['display_name' => 'seu nome', 'timezone' => 'o fuso horário'], '/conta');
        $this->users->updateProfile($u->id, (string) $data['display_name'], (string) $data['timezone']);
        $this->session->flash('success', 'Perfil atualizado.');
        return Response::redirect('/conta');
    }

    /** Consentimentos opcionais: retirar é tão simples quanto conceder (LGPD art. 8º, §5º). */
    public function updatePreferences(Request $r): Response
    {
        $u = $this->user($r);
        $data = $this->validate($r, ['marketing' => 'bool'], [], '/conta');
        $granted = $data['marketing'] === true;
        $current = $this->consents->currentForUser($u->id)['marketing_email']['granted'] ?? false;
        if ($current !== $granted) {
            $this->consents->record($u->id, null, 'marketing_email', $this->config->string('legal.privacy_version'), $granted, 'account', $this->ip($r));
        }
        $this->session->flash('success', 'Preferências salvas.');
        return Response::redirect('/conta');
    }

    public function changePassword(Request $r): Response
    {
        $u = $this->user($r);
        $data = $this->validate($r, [
            'current_password' => 'required|string|max:256',
            'password' => 'required|string|password|confirmed',
        ], ['current_password' => 'a senha atual', 'password' => 'a nova senha'], '/conta');
        if (!$this->auth->changePassword($u, (string) $data['current_password'], (string) $data['password'], $this->session->sessionId(), $this->ip($r))) {
            throw new ValidationFailed(['current_password' => ['Senha atual incorreta.']], '/conta');
        }
        $this->session->regenerate();
        $this->session->markReauthenticated();
        $this->session->flash('success', 'Senha alterada. As outras sessões abertas foram encerradas.');
        return Response::redirect('/conta');
    }

    public function changeEmail(Request $r): Response
    {
        $u = $this->user($r);
        $data = $this->validate($r, [
            'current_password' => 'required|string|max:256',
            'new_email' => 'required|email|max:254',
        ], ['current_password' => 'a senha atual', 'new_email' => 'o novo e-mail'], '/conta');
        $result = $this->auth->requestEmailChange($u, (string) $data['current_password'], (string) $data['new_email'], $this->ip($r));
        if ($result === 'bad_password') {
            throw new ValidationFailed(['current_password' => ['Senha atual incorreta.']], '/conta');
        }
        if ($result === 'same') {
            throw new ValidationFailed(['new_email' => ['Esse já é o seu e-mail atual.']], '/conta');
        }
        // 'sent' e 'taken' têm a mesma resposta (não revela contas existentes).
        $this->session->flash('info', 'Se o endereço puder ser usado, enviamos um link de confirmação para ele. A troca só acontece após a confirmação.');
        return Response::redirect('/conta');
    }

    public function showConfirmEmail(Request $r): Response
    {
        $token = is_string($r->query('token')) ? $r->query('token') : '';
        return $this->render('account/confirm-email', ['title' => 'Confirmar novo e-mail', 'token' => $token]);
    }

    public function confirmEmail(Request $r): Response
    {
        if (!$this->auth->confirmEmailChange($r->string('token'), $this->ip($r))) {
            $this->session->flash('error', 'Link inválido ou expirado.');
        } else {
            $this->session->flash('success', 'E-mail alterado. Avisamos o endereço anterior sobre a mudança.');
        }
        return Response::redirect($this->session->userId() !== null ? '/conta' : '/entrar');
    }

    // ---- Verificação em duas etapas ----

    public function showSecurity(Request $r): Response
    {
        $u = $this->user($r);
        $data = ['title' => 'Segurança da conta', 'user' => $u, 'noindex' => true];
        if ($u->mfaEnabled) {
            $data['remainingCodes'] = $this->mfa->remainingRecoveryCodes($u->id);
        } else {
            $enc = $this->session->get('mfa_setup');
            $secret = is_string($enc) ? $this->tryDecrypt($enc, $u->id) : null;
            if ($secret === null) {
                $secret = $this->mfa->newSecret();
                $this->session->set('mfa_setup', $this->crypto->encrypt($secret, 'mfa-setup:' . $u->id));
            }
            $data['secret'] = trim(chunk_split($secret, 4, ' '));
            $data['uri'] = $this->mfa->provisioningUri($secret, $u->email, $this->config->string('app.name', 'Concursei'));
        }
        return $this->render('account/security', $data);
    }

    private function tryDecrypt(string $enc, int $uid): ?string
    {
        try {
            return $this->crypto->decrypt($enc, 'mfa-setup:' . $uid);
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function enableMfa(Request $r): Response
    {
        $u = $this->user($r);
        $data = $this->validate($r, ['current_password' => 'required|string|max:256', 'code' => 'required|string|max:20'], ['current_password' => 'a senha atual', 'code' => 'o código'], '/conta/seguranca');
        if (!$this->auth->verifyPassword($u->id, (string) $data['current_password'])) {
            throw new ValidationFailed(['current_password' => ['Senha atual incorreta.']], '/conta/seguranca');
        }
        $enc = $this->session->get('mfa_setup');
        $secret = is_string($enc) ? $this->tryDecrypt($enc, $u->id) : null;
        $codes = $secret !== null ? $this->mfa->enable($u->id, $secret, (string) $data['code']) : null;
        if ($codes === null) {
            throw new ValidationFailed(['code' => ['Código inválido. Confira o horário do celular e tente novamente.']], '/conta/seguranca');
        }
        $this->session->forget('mfa_setup');
        $this->session->regenerate();
        $this->session->markReauthenticated();
        $this->audit->record($u->id, 'auth.mfa_enabled', 'user', $u->id, [], $this->ip($r));
        $this->auth->notifyMfaChanged($u->id, true);
        return $this->render('account/recovery-codes', ['title' => 'Códigos de recuperação', 'codes' => $codes])->header('Cache-Control', 'private, no-store');
    }

    /** Ações sobre o MFA ativo exigem senha atual + código (TOTP ou recuperação). */
    private function reauthenticateWithMfa(Request $r, AuthUser $u): void
    {
        $data = $this->validate($r, ['current_password' => 'required|string|max:256', 'code' => 'required|string|max:20'], ['current_password' => 'a senha atual', 'code' => 'o código'], '/conta/seguranca');
        if ($this->mfa->lockedOut($u->id)) {
            throw new ValidationFailed(['code' => ['Muitas tentativas de código. Aguarde 15 minutos.']], '/conta/seguranca');
        }
        if (!$this->auth->verifyPassword($u->id, (string) $data['current_password']) || !$this->mfa->verifyForUser($u->id, (string) $data['code'])) {
            $this->audit->record($u->id, 'auth.reauth_failed', 'user', $u->id, ['action' => 'mfa_manage'], $this->ip($r));
            throw new ValidationFailed(['code' => ['Senha ou código inválido.']], '/conta/seguranca');
        }
    }

    public function disableMfa(Request $r): Response
    {
        $u = $this->user($r);
        $this->reauthenticateWithMfa($r, $u);
        $this->mfa->disable($u->id);
        $this->audit->record($u->id, 'auth.mfa_disabled', 'user', $u->id, [], $this->ip($r));
        $this->auth->notifyMfaChanged($u->id, false);
        $this->session->flash('warning', $u->isStaff()
            ? 'Verificação em duas etapas desativada. O acesso à administração fica bloqueado até reativá-la.'
            : 'Verificação em duas etapas desativada.');
        return Response::redirect('/conta/seguranca');
    }

    public function regenerateCodes(Request $r): Response
    {
        $u = $this->user($r);
        $this->reauthenticateWithMfa($r, $u);
        $codes = $this->mfa->regenerateRecoveryCodes($u->id);
        $this->audit->record($u->id, 'auth.mfa_codes_regenerated', 'user', $u->id, [], $this->ip($r));
        return $this->render('account/recovery-codes', ['title' => 'Novos códigos de recuperação', 'codes' => $codes])->header('Cache-Control', 'private, no-store');
    }
}
