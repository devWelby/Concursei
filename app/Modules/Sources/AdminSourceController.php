<?php

declare(strict_types=1);

namespace Concursei\Modules\Sources;

use Concursei\Core\Http\Controller;
use Concursei\Core\Http\HttpException;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\Http\ValidationFailed;
use Concursei\Core\Net\SafeUrl;
use Concursei\Core\Net\UnsafeUrl;
use Concursei\Core\Session\Session;
use Concursei\Core\View\View;
use Concursei\Modules\Catalog\ReferenceRepository;
use Concursei\Modules\Jobs\Queue;
use Concursei\Shared\Audit\AuditLog;
use Concursei\Shared\Auth\AuthUser;

/** Cadastro de fontes (allowlist de domínios do coletor) e consulta dos documentos coletados. */
final class AdminSourceController extends Controller
{
    private const LABELS = [
        'name' => 'o nome', 'kind' => 'o tipo', 'method' => 'o modo de coleta', 'url' => 'o endereço', 'extra_hosts' => 'os domínios adicionais',
        'organization_id' => 'o órgão', 'exam_board_id' => 'a banca', 'contest_id' => 'o concurso', 'check_every_hours' => 'a frequência',
        'terms_note' => 'as condições de uso', 'enabled' => 'a situação',
    ];

    public function __construct(
        View $view,
        private SourceRepository $sources,
        private DocumentStore $store,
        private ReferenceRepository $refs,
        private Queue $queue,
        private Session $session,
        private AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    public function index(Request $r): Response
    {
        return $this->render('admin/sources/index', ['title' => 'Fontes', 'rows' => $this->sources->all()]);
    }

    public function create(Request $r): Response
    {
        return $this->render('admin/sources/form', ['title' => 'Nova fonte', 'row' => null, 'documents' => []] + $this->options());
    }

    public function edit(Request $r): Response
    {
        $row = $this->source($r);
        return $this->render('admin/sources/form', [
            'title' => 'Fonte: ' . $row['name'], 'row' => $row, 'documents' => $this->sources->documents((int) $row['id']),
        ] + $this->options());
    }

    public function save(Request $r): Response
    {
        $id = $r->param('id') !== null ? (int) $this->source($r)['id'] : null;
        $back = '/admin/fontes/' . ($id ?? 'nova');
        $data = $this->validate($r, [
            'name' => 'required|string|min:3|max:200',
            'kind' => 'required|in:official,secondary',
            'method' => 'required|in:page,document',
            'url' => 'required|url|max:2048',
            'extra_hosts' => 'nullable|string|max:500',
            'organization_id' => 'nullable|int',
            'exam_board_id' => 'nullable|int',
            'contest_id' => 'nullable|int',
            'check_every_hours' => 'required|int|min:1|max:720',
            'terms_note' => 'nullable|string|max:500',
            'enabled' => 'bool',
        ], self::LABELS, $back);

        $errors = [];
        try {
            $data['url'] = SafeUrl::parse((string) $data['url'], [(string) parse_url((string) $data['url'], PHP_URL_HOST)])->url;
        } catch (UnsafeUrl $e) {
            $errors['url'] = [$e->getMessage()];
        }
        $hosts = [];
        foreach (explode(',', (string) $data['extra_hosts']) as $h) {
            $h = strtolower(trim($h));
            if ($h === '') {
                continue;
            }
            if (!SafeUrl::isDnsName(str_starts_with($h, '*.') ? substr($h, 2) : $h)) {
                $errors['extra_hosts'] = ["Domínio inválido: {$h}. Use nomes como cdn.orgao.gov.br ou *.orgao.gov.br (sem IP)."];
                break;
            }
            $hosts[] = $h;
        }
        $data['extra_hosts'] = $hosts !== [] ? implode(', ', array_unique($hosts)) : null;
        foreach (['organization_id' => 'organizations', 'exam_board_id' => 'exam_boards'] as $field => $table) {
            if ($data[$field] !== null && !$this->refs->exists($table, (int) $data[$field])) {
                $errors[$field] = ['Selecione um item da lista.'];
            }
        }
        if ($data['contest_id'] !== null && !isset($this->sources->contestOptions()[(int) $data['contest_id']])) {
            $errors['contest_id'] = ['Selecione um concurso da lista.'];
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors, $back, $r->only(array_keys(self::LABELS)));
        }
        $data['enabled'] = $data['enabled'] ? 1 : 0;

        $user = $this->user($r);
        if ($id === null) {
            $id = $this->sources->create($data, $user->id);
            $this->audit->record($user->id, 'source.create', 'source', $id, ['host' => parse_url((string) $data['url'], PHP_URL_HOST)]);
        } else {
            $this->sources->update($id, $data);
            $this->audit->record($user->id, 'source.update', 'source', $id, ['host' => parse_url((string) $data['url'], PHP_URL_HOST)]);
        }
        $this->session->flash('success', 'Fonte salva. A coleta roda pelo Cron; use "Verificar agora" para antecipar.');
        return Response::redirect('/admin/fontes/' . $id);
    }

    public function check(Request $r): Response
    {
        $row = $this->source($r);
        if ((int) $row['enabled'] !== 1) {
            $this->session->flash('error', 'Fonte desativada: ative antes de verificar.');
        } else {
            $this->queue->dispatch('source.fetch', ['source_id' => (int) $row['id']], 'source.fetch:' . $row['id'], $this->user($r)->id);
            $this->session->flash('success', 'Verificação na fila: roda na próxima execução do Cron (php bin/console schedule:run).');
        }
        return Response::redirect('/admin/fontes/' . $row['id']);
    }

    public function document(Request $r): Response
    {
        $doc = $this->sources->findDocument($r->intParam('id')) ?? throw HttpException::notFound();
        return $this->render('admin/sources/document', ['title' => 'Documento #' . $doc['id'], 'doc' => $doc]);
    }

    /** Download do original como anexo (nunca renderizado no domínio do site). */
    public function download(Request $r): Response
    {
        $doc = $this->sources->findDocument($r->intParam('id')) ?? throw HttpException::notFound();
        $bytes = $this->documentBytes($doc);
        $ext = pathinfo((string) $doc['storage_path'], PATHINFO_EXTENSION);
        return Response::download($bytes, 'documento-' . (int) $doc['id'] . '.' . $ext, $doc['mime_type'] === 'application/pdf' ? 'application/pdf' : 'application/octet-stream')
            ->header('Content-Security-Policy', 'sandbox');
    }

    /** @param array<string,mixed> $doc */
    private function documentBytes(array $doc): string
    {
        try {
            return $this->store->read((string) $doc['storage_path']);
        } catch (\RuntimeException) {
            throw HttpException::notFound();
        }
    }

    /** @return array<string,mixed> */
    private function source(Request $r): array
    {
        return $this->sources->find($r->intParam('id')) ?? throw HttpException::notFound();
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return [
            'organizations' => $this->refs->options('organizations'),
            'boards' => $this->refs->options('exam_boards'),
            'contests' => $this->sources->contestOptions(),
        ];
    }

    private function user(Request $r): AuthUser
    {
        $user = $r->attribute('user');
        return $user instanceof AuthUser ? $user : throw HttpException::unauthorized();
    }
}
