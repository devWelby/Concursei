<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Clock;
use Concursei\Core\Config;
use Concursei\Core\Http\Controller;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Http\ValidationFailed;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;
use Concursei\Shared\Auth\AuthUser;

/** Administração de concursos: painel da equipe, cópia de trabalho e fluxo editorial. */
final class AdminContestController extends Controller
{
    private const PER_PAGE = 30;

    private const LABELS = [
        'title' => 'o título', 'slug' => 'o endereço', 'organization_id' => 'o órgão', 'exam_board_id' => 'a banca',
        'edition_year' => 'o ano', 'uf' => 'a UF', 'status' => 'a situação', 'summary' => 'o resumo', 'fee' => 'a taxa',
        'fee_note' => 'a observação da taxa', 'official_url' => 'a página oficial', 'registration_url' => 'a página de inscrição',
        'source_url' => 'o documento oficial (fonte)', 'source_published_on' => 'a data do documento', 'version' => 'a versão',
    ];

    public function __construct(
        View $view,
        private ContestRepository $contests,
        private ReferenceRepository $refs,
        private PublicationService $publication,
        private Session $session,
        private Config $config,
        private Clock $clock,
    ) {
        parent::__construct($view);
    }

    public function dashboard(Request $r): Response
    {
        $days = $this->config->int('catalog.reverify_days', 30);
        return $this->render('admin/dashboard', [
            'title' => 'Administração',
            'counts' => $this->contests->counts(),
            'inReview' => $this->contests->list(['workflow' => 'in_review'], 1, 10)['rows'],
            'stale' => $this->contests->staleVerification($this->clock->plusSecondsDb(-$days * 86400)),
            'reverifyDays' => $days,
        ]);
    }

    public function index(Request $r): Response
    {
        $workflow = (string) $r->query('situacao', '');
        $workflow = in_array($workflow, ['draft', 'in_review', 'published', 'archived'], true) ? $workflow : null;
        $q = mb_substr(trim(is_string($r->query('q')) ? $r->query('q') : ''), 0, 100);
        $page = preg_match('/^[1-9]\d{0,3}$/', (string) $r->query('pagina', '1')) ? (int) $r->query('pagina') : 1;
        $result = $this->contests->list(['workflow' => $workflow, 'q' => $q], $page, self::PER_PAGE);
        return $this->render('admin/contests/index', [
            'title' => 'Concursos — administração',
            'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'perPage' => self::PER_PAGE,
            'workflow' => $workflow, 'q' => $q,
        ]);
    }

    public function create(Request $r): Response
    {
        return $this->render('admin/contests/create', ['title' => 'Novo concurso'] + $this->formOptions());
    }

    public function store(Request $r): Response
    {
        $back = '/admin/concursos/novo';
        [$data, $slug] = $this->contestInput($r, $back);
        $slug ??= Format::slugify($data['title']);
        if ($slug === '') {
            throw new ValidationFailed(['slug' => ['Informe um endereço com letras ou números.']], $back, $this->rawInput($r));
        }
        try {
            $id = $this->contests->create($slug, $data, $this->user($r)->id);
        } catch (SlugTaken) {
            throw new ValidationFailed(['slug' => ['Esse endereço já está em uso; informe outro.']], $back, $this->rawInput($r));
        }
        $this->session->flash('success', 'Concurso criado como rascunho. Cadastre cargos e datas e envie para revisão.');
        return Response::redirect('/admin/concursos/' . $id);
    }

    public function edit(Request $r): Response
    {
        $contest = $this->find($r);
        $user = $this->user($r);
        return $this->render('admin/contests/edit', [
            'title' => 'Editar: ' . $contest['title'],
            'contest' => $contest,
            'offers' => $this->contests->offers((int) $contest['id']),
            'events' => $this->contests->events((int) $contest['id']),
            'revisions' => $this->contests->revisions((int) $contest['id']),
            'blockers' => $this->publication->blockers($contest),
            'canPublish' => $user->can('contests.publish'),
            'canReview' => $user->can('contests.review'),
        ] + $this->formOptions());
    }

    public function update(Request $r): Response
    {
        $contest = $this->find($r);
        $id = (int) $contest['id'];
        $back = '/admin/concursos/' . $id;
        [$data, $slug] = $this->contestInput($r, $back);
        try {
            $this->contests->update($id, $this->version($r), $slug, $data, $this->user($r)->id);
        } catch (SlugTaken) {
            throw new ValidationFailed(['slug' => ['Esse endereço já está em uso; informe outro.']], $back, $this->rawInput($r));
        }
        $this->session->flash('success', $contest['published_revision_id'] !== null
            ? 'Alterações salvas como rascunho. O público continua vendo a versão publicada até a próxima publicação.'
            : 'Alterações salvas.');
        return Response::redirect($back);
    }

    public function submit(Request $r): Response
    {
        $id = (int) $this->find($r)['id'];
        return $this->attempt($id, fn () => $this->publication->submit($id, $this->version($r), $this->user($r)), 'Enviado para revisão.');
    }

    public function returnToDraft(Request $r): Response
    {
        $id = (int) $this->find($r)['id'];
        return $this->attempt($id, fn () => $this->publication->returnToDraft($id, $this->version($r), $this->user($r)), 'Devolvido para rascunho.');
    }

    public function publish(Request $r): Response
    {
        $id = (int) $this->find($r)['id'];
        $verified = in_array($r->input('verified'), ['1', 'on', 'sim'], true);
        $correction = in_array($r->input('is_correction'), ['1', 'on', 'sim'], true);
        return $this->attempt(
            $id,
            fn () => $this->publication->publish($id, $this->version($r), $this->user($r), $r->string('change_note'), $correction, $verified),
            'Publicado. A página pública já mostra esta revisão.'
        );
    }

    public function archive(Request $r): Response
    {
        $id = (int) $this->find($r)['id'];
        return $this->attempt($id, fn () => $this->publication->archive($id, $this->user($r)), 'Concurso arquivado: saiu do catálogo público.');
    }

    public function unarchive(Request $r): Response
    {
        $id = (int) $this->find($r)['id'];
        return $this->attempt($id, fn () => $this->publication->unarchive($id, $this->user($r)), 'Concurso reativado com a última revisão publicada.');
    }

    public function destroy(Request $r): Response
    {
        $id = (int) $this->find($r)['id'];
        try {
            $this->publication->delete($id, $this->version($r), $this->user($r));
        } catch (WorkflowViolation $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/admin/concursos/' . $id);
        }
        $this->session->flash('success', 'Rascunho excluído.');
        return Response::redirect('/admin/concursos');
    }

    /** @return array{0:array<string,mixed>,1:?string} dados da cópia de trabalho e slug informado */
    private function contestInput(Request $r, string $back): array
    {
        $data = $this->validate($r, [
            'title' => 'required|string|min:3|max:200',
            'slug' => 'nullable|slug',
            'organization_id' => 'required|int|min:1',
            'exam_board_id' => 'nullable|int|min:1',
            'edition_year' => 'nullable|int|min:2000|max:2100',
            'uf' => 'nullable|in:' . Vocabulary::keys(Vocabulary::UFS),
            'status' => 'required|in:' . Vocabulary::keys(Vocabulary::STATUSES),
            'summary' => 'nullable|string|max:5000',
            'fee' => 'nullable|string|max:20',
            'fee_note' => 'nullable|string|max:500',
            'official_url' => 'nullable|url',
            'registration_url' => 'nullable|url',
            'source_url' => 'nullable|url',
            'source_published_on' => 'nullable|date',
        ], self::LABELS, $back);
        $errors = [];
        if (!$this->refs->exists('organizations', $data['organization_id'])) {
            $errors['organization_id'] = ['Selecione um órgão cadastrado.'];
        }
        if ($data['exam_board_id'] !== null && !$this->refs->exists('exam_boards', $data['exam_board_id'])) {
            $errors['exam_board_id'] = ['Selecione uma banca cadastrada.'];
        }
        $fee = Format::parseMoney((string) ($data['fee'] ?? ''));
        if ($fee === false) {
            $errors['fee'] = ['Taxa: use o formato 120,00 (sem valores negativos).'];
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors, $back, $this->rawInput($r));
        }
        $slug = $data['slug'];
        unset($data['slug'], $data['fee']);
        $data['fee_cents'] = $fee;
        return [$data, $slug];
    }

    /** @return array<string,mixed> */
    private function rawInput(Request $r): array
    {
        return $r->only(array_keys(self::LABELS));
    }

    /** @return array<string,mixed> */
    private function formOptions(): array
    {
        return [
            'organizations' => $this->refs->options('organizations'),
            'boards' => $this->refs->options('exam_boards'),
        ];
    }

    /** @return array<string,mixed> */
    private function find(Request $r): array
    {
        return $this->contests->find($r->intParam('id')) ?? throw HttpException::notFound();
    }

    private function version(Request $r): int
    {
        $v = $r->string('version');
        if (!preg_match('/^[1-9]\d{0,9}$/', $v)) {
            throw new StaleVersion();
        }
        return (int) $v;
    }

    private function user(Request $r): AuthUser
    {
        $user = $r->attribute('user');
        return $user instanceof AuthUser ? $user : throw HttpException::unauthorized();
    }

    private function attempt(int $id, callable $action, string $success): Response
    {
        try {
            $action();
            $this->session->flash('success', $success);
        } catch (WorkflowViolation $e) {
            $this->session->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/concursos/' . $id);
    }
}
