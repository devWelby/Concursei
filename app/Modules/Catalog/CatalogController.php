<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Config;
use Concursei\Core\Http\Controller;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;
use Concursei\Shared\Auth\AuthUser;
use DateTimeImmutable;

/** Páginas públicas do catálogo: listagem, detalhe e calendário. */
final class CatalogController extends Controller
{
    public function __construct(
        View $view,
        private PublicCatalog $catalog,
        private FavoriteRepository $favorites,
        private LocalDate $date,
        private Session $session,
        private Config $config,
    ) {
        parent::__construct($view);
    }

    public function index(Request $r): Response
    {
        $filters = ContestFilters::fromQuery($r->allQuery());
        $perPage = $this->config->int('catalog.per_page', 20);
        $result = $this->catalog->search($filters, $perPage);
        if ($filters->page > 1 && $result['rows'] === []) {
            throw HttpException::notFound();
        }
        return $this->render('catalog/index', [
            'title' => 'Concursos públicos abertos e previstos',
            'metaDescription' => 'Concursos públicos com inscrições abertas, editais publicados e previstos, com datas, vagas, remuneração e fonte oficial conferida.',
            'filters' => $filters,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'perPage' => $perPage,
            'organizations' => $this->catalog->organizations(),
            'boards' => $this->catalog->boards(),
            'today' => $this->date->today(),
        ]);
    }

    public function show(Request $r): Response
    {
        $contest = $this->findPublic($r);
        $user = $r->attribute('user');
        return $this->render('catalog/show', [
            'title' => $contest['title'],
            'metaDescription' => self::describe($contest),
            'contest' => $contest,
            'user' => $user instanceof AuthUser ? $user : null,
            'favoriteId' => $user instanceof AuthUser ? $this->favorites->idFor($user->id, (int) $contest['contest_id']) : null,
            'today' => $this->date->today(),
        ]);
    }

    /** Destino do "Acompanhar" para quem não estava logado: depois do login, volta ao concurso. */
    public function follow(Request $r): Response
    {
        $contest = $this->findPublic($r);
        $this->session->flash('info', 'Para acompanhar, use o botão "Acompanhar este concurso".');
        return Response::redirect('/concursos/' . $contest['slug'] . '#acompanhar');
    }

    public function calendar(Request $r): Response
    {
        $today = $this->date->today();
        $mes = $r->query('mes');
        $month = is_string($mes) && preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $mes)
            ? new DateTimeImmutable($mes . '-01', $today->getTimezone())
            : $today->modify('first day of this month');
        $filters = ContestFilters::fromQuery($r->allQuery());
        $type = $r->query('tipo');
        $type = is_string($type) && isset(Vocabulary::EVENT_TYPES[$type]) ? $type : null;
        return $this->render('catalog/calendar', [
            'title' => 'Calendário de concursos — ' . Format::monthLabel($month),
            'metaDescription' => 'Inscrições, provas, gabaritos e resultados de concursos públicos em ' . Format::monthLabel($month) . '.',
            'month' => $month,
            'today' => $today,
            'filters' => $filters,
            'type' => $type,
            'data' => $this->catalog->calendar($month, $filters->uf, $filters->region, $type),
        ]);
    }

    /** @return array<string,mixed> */
    private function findPublic(Request $r): array
    {
        $slug = (string) $r->param('slug');
        if (strlen($slug) > 190) {
            throw HttpException::notFound();
        }
        return $this->catalog->findBySlug($slug) ?? throw HttpException::notFound();
    }

    /** @param array<string,mixed> $c */
    private static function describe(array $c): string
    {
        $parts = [Vocabulary::label(Vocabulary::PHASES, $c['phase'])];
        if ($c['vacancies_total'] !== null || (int) $c['has_reserve'] === 1) {
            $parts[] = Format::vacancies($c['vacancies_total'] !== null ? (int) $c['vacancies_total'] : null, (int) $c['has_reserve'] === 1);
        }
        $parts[] = $c['exam_board_name'] !== null ? 'banca ' . $c['exam_board_name'] : 'banca a definir';
        return mb_substr($c['title'] . ': ' . implode(', ', $parts) . '. Datas, cargos, remuneração e fonte oficial.', 0, 300);
    }
}
