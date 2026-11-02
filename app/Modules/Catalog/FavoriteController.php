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

/** Acompanhar concursos e o painel da pessoa logada. */
final class FavoriteController extends Controller
{
    private const UPCOMING_DAYS = 60;

    public function __construct(
        View $view,
        private FavoriteRepository $favorites,
        private PublicCatalog $catalog,
        private LocalDate $date,
        private Session $session,
        private Config $config,
    ) {
        parent::__construct($view);
    }

    public function store(Request $r): Response
    {
        $user = $this->user($r);
        $data = $this->validate($r, ['contest_id' => 'required|int|min:1'], ['contest_id' => 'o concurso'], '/concursos');
        $contestId = (int) $data['contest_id'];
        // Só concursos publicados e ativos podem ser acompanhados.
        $slug = $this->catalog->slugFor($contestId) ?? throw HttpException::notFound();
        $max = $this->config->int('catalog.max_favorites', 200);
        if ($this->favorites->idFor($user->id, $contestId) === null && $this->favorites->count($user->id) >= $max) {
            $this->session->flash('error', "Você já acompanha {$max} concursos, o máximo permitido. Remova algum no seu painel.");
            return Response::redirect('/concursos/' . $slug);
        }
        $this->favorites->add($user->id, $contestId);
        $this->session->flash('success', 'Pronto! As próximas datas deste concurso aparecem no seu painel.');
        return Response::redirect('/concursos/' . $slug . '#acompanhar');
    }

    public function destroy(Request $r): Response
    {
        $user = $this->user($r);
        // Remoção filtra por dono: id de favorito de outra pessoa responde 404, sem revelar que existe.
        $contestId = $this->favorites->remove($user->id, $r->intParam('id')) ?? throw HttpException::notFound();
        $this->session->flash('success', 'Você deixou de acompanhar o concurso.');
        $slug = $r->string('voltar') === 'painel' ? null : $this->catalog->slugFor($contestId);
        return Response::redirect($slug !== null ? '/concursos/' . $slug . '#acompanhar' : '/painel');
    }

    public function dashboard(Request $r): Response
    {
        $user = $this->user($r);
        $today = $this->date->today();
        return $this->render('dashboard/index', [
            'title' => 'Meu painel',
            'user' => $user,
            'today' => $today,
            'rows' => $this->favorites->forUser($user->id),
            'upcoming' => $this->favorites->upcomingEvents($user->id, $today->format('Y-m-d'), $today->modify('+' . self::UPCOMING_DAYS . ' days')->format('Y-m-d')),
            'upcomingDays' => self::UPCOMING_DAYS,
        ]);
    }

    private function user(Request $r): AuthUser
    {
        $user = $r->attribute('user');
        return $user instanceof AuthUser ? $user : throw HttpException::unauthorized();
    }
}
