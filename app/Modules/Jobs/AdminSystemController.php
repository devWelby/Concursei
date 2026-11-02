<?php

declare(strict_types=1);

namespace Concursei\Modules\Jobs;

use Concursei\Core\Clock;
use Concursei\Core\Http\Controller;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;
use Concursei\Shared\Audit\AuditLog;
use Concursei\Shared\Auth\AuthUser;

/** Painel de saúde: heartbeat do Cron, fila, jobs parados e dead letters com reprocessamento. */
final class AdminSystemController extends Controller
{
    public function __construct(
        View $view,
        private Queue $queue,
        private Scheduler $scheduler,
        private Session $session,
        private AuditLog $audit,
        private Clock $clock,
    ) {
        parent::__construct($view);
    }

    public function index(Request $r): Response
    {
        return $this->render('admin/system/index', [
            'title' => 'Saúde do sistema',
            'stats' => $this->queue->stats(),
            'heartbeats' => $this->scheduler->heartbeats(),
            'tasks' => $this->scheduler->tasks(),
            'failed' => $this->queue->recent('failed', 30),
            'waiting' => array_merge($this->queue->recent('retry_scheduled', 20), $this->queue->recent('pending', 20)),
            'now' => $this->clock->now(),
        ]);
    }

    public function requeue(Request $r): Response
    {
        $id = $r->intParam('id');
        $this->job($id);
        if ($this->queue->requeue($id)) {
            $this->audit->record($this->user($r)->id, 'job.requeue', 'job', $id);
            $this->session->flash('success', "Job #{$id} voltou para a fila.");
        } else {
            $this->session->flash('error', "Job #{$id} não está falho nem cancelado.");
        }
        return Response::redirect('/admin/sistema');
    }

    public function cancel(Request $r): Response
    {
        $id = $r->intParam('id');
        $this->job($id);
        if ($this->queue->cancel($id)) {
            $this->audit->record($this->user($r)->id, 'job.cancel', 'job', $id);
            $this->session->flash('success', "Job #{$id} cancelado.");
        } else {
            $this->session->flash('error', "Job #{$id} já está em execução ou terminou.");
        }
        return Response::redirect('/admin/sistema');
    }

    private function job(int $id): void
    {
        if ($this->queue->find($id) === null) {
            throw HttpException::notFound();
        }
    }

    private function user(Request $r): AuthUser
    {
        $user = $r->attribute('user');
        if (!$user instanceof AuthUser) {
            throw HttpException::unauthorized();
        }
        return $user;
    }
}
