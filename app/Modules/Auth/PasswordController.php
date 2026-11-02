<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth;

use Concursei\Core\Config;
use Concursei\Core\Http\Controller;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;

final class PasswordController extends Controller
{
    public function __construct(View $view, private AuthService $auth, private Session $session, private Config $config)
    {
        parent::__construct($view);
    }

    private function ip(Request $r): string
    {
        return $r->clientIp($this->config->get('security.trusted_proxies', []));
    }

    public function showForgot(Request $r): Response
    {
        return $this->render('auth/forgot', ['title' => 'Recuperar senha', 'mailAvailable' => $this->auth->mailAvailable()]);
    }

    public function forgot(Request $r): Response
    {
        $data = $this->validate($r, ['email' => 'required|email|max:254'], ['email' => 'o e-mail'], '/recuperar-senha');
        $this->auth->requestPasswordReset((string) $data['email'], $this->ip($r));
        $this->session->flash('info', 'Se existir uma conta ativa com esse e-mail, enviamos um link para redefinir a senha. Ele vale por 1 hora.');
        return Response::redirect('/recuperar-senha');
    }

    public function showReset(Request $r): Response
    {
        $token = is_string($r->query('token')) ? $r->query('token') : '';
        return $this->render('auth/reset', [
            'title' => 'Definir nova senha',
            'token' => $token,
            'valid' => $token !== '' && $this->auth->resetTokenValid($token),
        ]);
    }

    public function reset(Request $r): Response
    {
        $token = $r->string('token');
        $back = '/redefinir-senha?token=' . rawurlencode($token);
        $data = $this->validate($r, ['password' => 'required|string|password|confirmed'], ['password' => 'a nova senha'], $back);
        if (!$this->auth->resetPassword($token, (string) $data['password'], $this->ip($r))) {
            $this->session->flash('error', 'Link inválido ou expirado. Solicite uma nova redefinição.');
            return Response::redirect('/recuperar-senha');
        }
        $this->session->invalidate();
        $this->session->flash('success', 'Senha redefinida. Por segurança, todas as sessões foram encerradas; entre novamente.');
        return Response::redirect('/entrar');
    }
}
