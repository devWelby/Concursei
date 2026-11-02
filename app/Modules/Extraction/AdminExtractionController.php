<?php

declare(strict_types=1);

namespace Concursei\Modules\Extraction;

use Concursei\Core\Http\Controller;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;
use Concursei\Modules\Ai\AiGateway;
use Concursei\Modules\Catalog\ContestRepository;
use Concursei\Modules\Catalog\StaleVersion;
use Concursei\Modules\Catalog\WorkflowViolation;
use Concursei\Modules\Sources\SourceRepository;
use Concursei\Shared\Auth\AuthUser;

/** Pedido de extração assistida e tela de revisão humana das propostas. */
final class AdminExtractionController extends Controller
{
    private const STATUSES = ['queued', 'running', 'ready', 'failed', 'applied', 'rejected'];

    public function __construct(
        View $view,
        private ExtractionService $service,
        private ExtractionRepository $extractions,
        private SourceRepository $sources,
        private ContestRepository $contests,
        private AiGateway $ai,
        private Session $session,
    ) {
        parent::__construct($view);
    }

    public function form(Request $r): Response
    {
        $doc = $this->sources->findDocument($r->intParam('id')) ?? throw HttpException::notFound();
        return $this->render('admin/extractions/request', [
            'title' => 'Extração assistida — documento #' . $doc['id'],
            'doc' => $doc,
            'contests' => $this->sources->contestOptions(),
            'history' => $this->extractions->forDocument((int) $doc['id']),
            'aiAvailable' => $this->ai->isAvailable(),
        ]);
    }

    public function request(Request $r): Response
    {
        $docId = $r->intParam('id');
        $back = '/admin/documentos/' . $docId . '/extrair';
        $data = $this->validate($r, ['contest_id' => 'required|int|min:1'], ['contest_id' => 'o concurso'], $back);
        try {
            $result = $this->service->request($docId, (int) $data['contest_id'], $this->user($r)->id);
        } catch (ExtractionRefused $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect($back);
        }
        $this->session->flash($result['outcome'] === 'existing' ? 'info' : 'success', match ($result['outcome']) {
            'cached' => 'Este texto já foi analisado com a mesma versão do prompt: proposta reaproveitada, sem nova chamada à IA.',
            'existing' => 'Já existe uma proposta em andamento ou aguardando revisão para este documento e concurso.',
            default => 'Extração na fila: roda na próxima execução do Cron. Atualize esta página para ver o resultado.',
        });
        return Response::redirect('/admin/extracoes/' . $result['id']);
    }

    public function index(Request $r): Response
    {
        $status = in_array($r->query('status'), self::STATUSES, true) ? (string) $r->query('status') : null;
        return $this->render('admin/extractions/index', [
            'title' => 'Propostas da IA', 'rows' => $this->extractions->recent($status), 'status' => $status,
        ]);
    }

    public function show(Request $r): Response
    {
        $x = $this->extractions->find($r->intParam('id')) ?? throw HttpException::notFound();
        $contest = $x['contest_id'] !== null ? $this->contests->find((int) $x['contest_id']) : null;
        $diff = $x['status'] === 'ready' && $contest !== null && is_array($x['proposal']) ? $this->service->compare($x['proposal'], $contest) : [];
        return $this->render('admin/extractions/show', [
            'title' => 'Proposta da IA #' . $x['id'], 'x' => $x, 'contest' => $contest, 'diff' => $diff,
        ]);
    }

    public function apply(Request $r): Response
    {
        $id = $r->intParam('id');
        $keys = $r->input('items', []);
        $keys = is_array($keys) ? array_values(array_filter($keys, static fn (mixed $k): bool => is_string($k) && preg_match('/^[feo]\d{1,3}$/', $k) === 1)) : [];
        $version = $r->string('version');
        if (!preg_match('/^[1-9]\d{0,9}$/', $version)) {
            throw new StaleVersion();
        }
        try {
            $s = $this->service->apply($id, (int) $version, $keys, $r->input('use_as_source') === '1', $this->user($r)->id);
        } catch (ExtractionRefused | WorkflowViolation $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/admin/extracoes/' . $id);
        }
        $x = $this->extractions->find($id);
        $this->session->flash('success', sprintf(
            'Aplicado ao rascunho: %d data(s), %d cargo(s)%s%s. Confira com o documento e publique pelo fluxo de revisão.',
            $s['events'], $s['offers'], $s['fee'] ? ', taxa' : '', $s['source'] ? ', documento como fonte' : ''
        ));
        return Response::redirect('/admin/concursos/' . (int) ($x['contest_id'] ?? 0));
    }

    public function reject(Request $r): Response
    {
        $id = $r->intParam('id');
        $note = trim($r->string('review_note'));
        try {
            $this->service->reject($id, $note !== '' ? $note : null, $this->user($r)->id);
            $this->session->flash('success', 'Proposta descartada. Nada foi alterado no concurso.');
        } catch (ExtractionRefused $e) {
            $this->session->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/extracoes/' . $id);
    }

    private function user(Request $r): AuthUser
    {
        $user = $r->attribute('user');
        return $user instanceof AuthUser ? $user : throw HttpException::unauthorized();
    }
}
