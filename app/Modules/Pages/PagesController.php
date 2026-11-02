<?php

declare(strict_types=1);

namespace Concursei\Modules\Pages;

use Concursei\Core\Config;
use Concursei\Core\Database\Database;
use Concursei\Core\Http\Controller;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;

/**
 * Páginas institucionais. Os textos descrevem o que o sistema realmente faz; a identidade do
 * responsável vem do .env (OPERATOR_NAME/CONTACT_EMAIL) e nunca é inventada.
 */
final class PagesController extends Controller
{
    public function __construct(View $view, private Config $config, private Database $db, private Session $session)
    {
        parent::__construct($view);
    }

    public function about(Request $r): Response
    {
        return $this->page('pages/about', 'Sobre e metodologia');
    }

    public function privacy(Request $r): Response
    {
        return $this->page('pages/privacy', 'Política de privacidade', [
            'idleHours' => intdiv($this->config->int('session.idle_seconds', 7200), 3600),
            'absoluteDays' => intdiv($this->config->int('session.absolute_seconds', 1209600), 86400),
        ]);
    }

    public function terms(Request $r): Response
    {
        return $this->page('pages/terms', 'Termos de uso');
    }

    public function cookies(Request $r): Response
    {
        // Nome real do cookie neste ambiente (__Host- exige HTTPS; em desenvolvimento local o nome é outro).
        $name = $this->session->cookieName();
        return $this->page('pages/cookies', 'Cookies', ['cookieName' => $name, 'cookieSecure' => str_starts_with($name, '__Host-')]);
    }

    /** Registro público de correções: só revisões publicadas marcadas como correção, de concursos visíveis. */
    public function corrections(Request $r): Response
    {
        $rows = $this->db->all(
            'SELECT cs.slug, cs.title, cs.organization_name, r.revision_no, r.change_note, r.published_at
               FROM contest_revisions r
               JOIN contest_search cs ON cs.contest_id = r.contest_id
              WHERE r.is_correction = 1
              ORDER BY r.published_at DESC, r.id DESC
              LIMIT 100'
        );
        return $this->page('pages/corrections', 'Correções', ['rows' => $rows]);
    }

    /** @param array<string,mixed> $data */
    private function page(string $template, string $title, array $data = []): Response
    {
        return $this->render($template, $data + [
            'title' => $title,
            'operator' => trim($this->config->string('app.operator_name')),
            'contact' => trim($this->config->string('app.contact_email')),
            'termsVersion' => $this->config->string('legal.terms_version'),
            'privacyVersion' => $this->config->string('legal.privacy_version'),
            'minimumAge' => $this->config->int('legal.minimum_age', 18),
        ]);
    }
}
