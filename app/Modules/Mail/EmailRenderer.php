<?php

declare(strict_types=1);

namespace Concursei\Modules\Mail;

use Concursei\Core\Config;
use Concursei\Core\View\View;
use InvalidArgumentException;

/**
 * Renderiza e-mails a partir de templates de TEXTO (resources/views/emails).
 * O HTML é derivado do texto com escape total; só URLs https viram links.
 */
final class EmailRenderer
{
    /** Assuntos por template (allowlist de templates). */
    private const SUBJECTS = [
        'auth_verify' => 'Confirme seu e-mail no Concursei',
        'auth_already_registered' => 'Tentativa de cadastro com seu e-mail',
        'auth_reset' => 'Redefinição de senha do Concursei',
        'auth_password_changed' => 'Sua senha do Concursei foi alterada',
        'auth_email_change' => 'Confirme seu novo e-mail no Concursei',
        'auth_email_changed' => 'O e-mail da sua conta Concursei foi alterado',
        'auth_mfa_changed' => 'Verificação em duas etapas alterada na sua conta',
    ];

    public function __construct(private View $view, private Config $config)
    {
    }

    /** @param array<string,mixed> $vars @return array{subject:string,text:string,html:string} */
    public function render(string $template, array $vars): array
    {
        $subject = self::SUBJECTS[$template] ?? ($vars['subject'] ?? null);
        if (!is_string($subject) || !preg_match('/^[a-z0-9_]+$/', $template)) {
            throw new InvalidArgumentException('Template de e-mail desconhecido.');
        }
        if (array_key_exists('name', $vars)) {
            $vars['name'] = self::safeName(is_string($vars['name']) ? $vars['name'] : '');
        }
        $vars += ['appName' => $this->config->string('app.name', 'Concursei'), 'appUrl' => $this->config->string('app.url')];
        $text = trim($this->view->render('emails/' . $template, $vars, null));
        $text .= "\n\n—\n" . $vars['appName'] . ' · ' . $vars['appUrl'] . "\nEste é um e-mail automático; não responda.";
        return ['subject' => $subject, 'text' => $text, 'html' => self::textToHtml($text)];
    }

    /**
     * Nome digitado por terceiros vai para a caixa de outra pessoa (ex.: cadastro com
     * e-mail alheio). Para o site não servir de canal de phishing, nomes com aparência
     * de link/endereço são omitidos e o restante vira uma linha curta.
     */
    public static function safeName(string $name): string
    {
        $name = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $name));
        if ($name === '' || preg_match('#(://|www\.|@|\.[a-z]{2,}(\b|/)|[<>])#iu', $name)) {
            return '';
        }
        return mb_substr($name, 0, 40);
    }

    public static function textToHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $linked = (string) preg_replace('#(https://[A-Za-z0-9._~:/?\#\[\]@!$&\'()*+,;=%-]+)#', '<a href="$1">$1</a>', $escaped);
        return '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><body style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#1b1f24">'
            . nl2br($linked) . '</body></html>';
    }
}
