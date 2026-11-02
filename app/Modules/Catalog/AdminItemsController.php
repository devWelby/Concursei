<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Http\Controller;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Http\ValidationFailed;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;
use Concursei\Shared\Auth\AuthUser;

/** Cargos (ofertas) e datas de um concurso na área administrativa. */
final class AdminItemsController extends Controller
{
    private const OFFER_LABELS = [
        'title' => 'o nome do cargo', 'career_id' => 'a carreira', 'education_level' => 'a escolaridade', 'vacancies' => 'as vagas',
        'reserve' => 'o cadastro de reserva', 'salary' => 'a remuneração', 'salary_note' => 'a composição da remuneração',
        'workload_hours' => 'a carga horária', 'requirements' => 'os requisitos', 'location' => 'a lotação', 'sort_order' => 'a ordem',
    ];
    private const EVENT_LABELS = ['type' => 'o tipo', 'label' => 'a descrição', 'date' => 'a data', 'time' => 'o horário', 'note' => 'a observação'];

    public function __construct(
        View $view,
        private ContestRepository $contests,
        private ReferenceRepository $refs,
        private Session $session,
    ) {
        parent::__construct($view);
    }

    public function newOffer(Request $r): Response
    {
        return $this->offerForm($this->contest($r), null);
    }

    public function editOffer(Request $r): Response
    {
        $contest = $this->contest($r);
        $offer = $this->contests->findOffer((int) $contest['id'], $r->intParam('item')) ?? throw HttpException::notFound();
        return $this->offerForm($contest, $offer);
    }

    public function saveOffer(Request $r): Response
    {
        $contest = $this->contest($r);
        $itemId = $r->param('item') !== null ? $r->intParam('item') : null;
        $base = '/admin/concursos/' . $contest['id'];
        $back = $base . '/cargos/' . ($itemId ?? 'novo');
        $data = $this->validate($r, [
            'title' => 'required|string|min:2|max:200',
            'career_id' => 'nullable|int|min:1',
            'education_level' => 'required|in:' . Vocabulary::keys(Vocabulary::EDUCATION_LEVELS),
            'vacancies' => 'nullable|int|min:0|max:100000',
            'reserve' => 'bool',
            'salary' => 'nullable|string|max:20',
            'salary_note' => 'nullable|string|max:500',
            'workload_hours' => 'nullable|int|min:1|max:60',
            'requirements' => 'nullable|string|max:3000',
            'location' => 'nullable|string|max:200',
            'sort_order' => 'nullable|int|min:0|max:999',
        ], self::OFFER_LABELS, $back);
        $errors = [];
        if ($data['career_id'] !== null && !$this->refs->exists('careers', $data['career_id'])) {
            $errors['career_id'] = ['Selecione uma carreira cadastrada.'];
        }
        $salary = Format::parseMoney((string) ($data['salary'] ?? ''));
        if ($salary === false) {
            $errors['salary'] = ['Remuneração: use o formato 4.500,00.'];
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors, $back, $r->only(array_keys(self::OFFER_LABELS)));
        }
        unset($data['salary']);
        $data['salary_cents'] = $salary;
        $data['reserve'] = $data['reserve'] ? 1 : 0;
        $data['sort_order'] ??= 0;
        return $this->persist($base, fn () => $this->contests->saveOffer((int) $contest['id'], $this->version($r), $itemId, $data, $this->user($r)->id), 'Cargo salvo.');
    }

    public function deleteOffer(Request $r): Response
    {
        $contest = $this->contest($r);
        $base = '/admin/concursos/' . $contest['id'];
        if (!$this->contests->deleteOffer((int) $contest['id'], $this->version($r), $r->intParam('item'), $this->user($r)->id)) {
            throw HttpException::notFound();
        }
        $this->session->flash('success', 'Cargo removido.');
        return Response::redirect($base);
    }

    public function newEvent(Request $r): Response
    {
        return $this->eventForm($this->contest($r), null);
    }

    public function editEvent(Request $r): Response
    {
        $contest = $this->contest($r);
        $event = $this->contests->findEvent((int) $contest['id'], $r->intParam('item')) ?? throw HttpException::notFound();
        return $this->eventForm($contest, $event);
    }

    public function saveEvent(Request $r): Response
    {
        $contest = $this->contest($r);
        $itemId = $r->param('item') !== null ? $r->intParam('item') : null;
        $base = '/admin/concursos/' . $contest['id'];
        $back = $base . '/datas/' . ($itemId ?? 'nova');
        $data = $this->validate($r, [
            'type' => 'required|in:' . Vocabulary::keys(Vocabulary::EVENT_TYPES),
            'label' => 'nullable|string|max:200',
            'date' => 'nullable|string|max:10',
            'note' => 'nullable|string|max:300',
            'time' => 'nullable|regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/',
        ], self::EVENT_LABELS, $back);
        $errors = [];
        $parsed = Format::parseEventDate((string) ($data['date'] ?? ''));
        if ($parsed === false) {
            $errors['date'] = ['Use DD/MM/AAAA (dia exato), MM/AAAA (só o mês) ou AAAA (só o ano); deixe vazio se ainda não há data.'];
        } elseif ($data['time'] !== null && $parsed['precision'] !== 'day') {
            $errors['time'] = ['Horário só pode ser informado junto com a data exata (dia).'];
        }
        if ($data['type'] === 'other' && $data['label'] === null) {
            $errors['label'] = ['Descreva o evento.'];
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors, $back, $r->only(array_keys(self::EVENT_LABELS)));
        }
        $row = [
            'type' => $data['type'],
            'label' => $data['label'],
            'date_value' => $parsed['date'],
            'date_precision' => $parsed['precision'],
            'time_local' => $data['time'] !== null ? substr($data['time'], 0, 5) . ':00' : null,
            'note' => $data['note'],
        ];
        return $this->persist($base, fn () => $this->contests->saveEvent((int) $contest['id'], $this->version($r), $itemId, $row, $this->user($r)->id), 'Data salva.');
    }

    public function deleteEvent(Request $r): Response
    {
        $contest = $this->contest($r);
        $base = '/admin/concursos/' . $contest['id'];
        if (!$this->contests->deleteEvent((int) $contest['id'], $this->version($r), $r->intParam('item'), $this->user($r)->id)) {
            throw HttpException::notFound();
        }
        $this->session->flash('success', 'Data removida.');
        return Response::redirect($base);
    }

    /** @param array<string,mixed>|null $offer */
    private function offerForm(array $contest, ?array $offer): Response
    {
        return $this->render('admin/contests/offer', [
            'title' => ($offer === null ? 'Novo cargo' : 'Editar cargo') . ' — ' . $contest['title'],
            'contest' => $contest,
            'offer' => $offer,
            'careers' => $this->refs->options('careers'),
        ]);
    }

    /** @param array<string,mixed>|null $event */
    private function eventForm(array $contest, ?array $event): Response
    {
        return $this->render('admin/contests/event', [
            'title' => ($event === null ? 'Nova data' : 'Editar data') . ' — ' . $contest['title'],
            'contest' => $contest,
            'event' => $event,
        ]);
    }

    private function persist(string $base, callable $action, string $success): Response
    {
        try {
            $action();
            $this->session->flash('success', $success);
        } catch (WorkflowViolation $e) {
            $this->session->flash('error', $e->getMessage());
        }
        return Response::redirect($base);
    }

    /** @return array<string,mixed> */
    private function contest(Request $r): array
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
}
