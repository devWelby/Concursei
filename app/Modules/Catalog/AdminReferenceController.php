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

/**
 * Órgãos, bancas e cargos. Mudanças aqui só aparecem ao público na próxima
 * publicação de cada concurso (a revisão publicada guarda os nomes).
 */
final class AdminReferenceController extends Controller
{
    /** Segmento da URL => tabela e rótulos (allowlist). */
    private const KINDS = [
        'orgaos' => ['table' => 'organizations', 'title' => 'Órgãos', 'new' => 'Novo órgão'],
        'bancas' => ['table' => 'exam_boards', 'title' => 'Bancas', 'new' => 'Nova banca'],
        'cargos' => ['table' => 'careers', 'title' => 'Cargos e carreiras', 'new' => 'Nova carreira'],
    ];
    private const LABELS = [
        'name' => 'o nome', 'slug' => 'o endereço', 'acronym' => 'a sigla', 'sphere' => 'a esfera', 'uf' => 'a UF',
        'website_url' => 'o site oficial', 'area' => 'a área', 'education_level' => 'a escolaridade', 'description' => 'a descrição',
    ];

    public function __construct(View $view, private ReferenceRepository $refs, private Session $session)
    {
        parent::__construct($view);
    }

    public function index(Request $r): Response
    {
        [$kind, $cfg] = $this->kind($r);
        return $this->render('admin/references/index', ['title' => $cfg['title'], 'kind' => $kind, 'cfg' => $cfg, 'rows' => $this->refs->all($cfg['table'])]);
    }

    public function create(Request $r): Response
    {
        [$kind, $cfg] = $this->kind($r);
        return $this->render('admin/references/form', ['title' => $cfg['new'], 'kind' => $kind, 'cfg' => $cfg, 'row' => null]);
    }

    public function edit(Request $r): Response
    {
        [$kind, $cfg] = $this->kind($r);
        $row = $this->refs->find($cfg['table'], $r->intParam('id')) ?? throw HttpException::notFound();
        return $this->render('admin/references/form', ['title' => 'Editar: ' . $row['name'], 'kind' => $kind, 'cfg' => $cfg, 'row' => $row]);
    }

    public function save(Request $r): Response
    {
        [$kind, $cfg] = $this->kind($r);
        $id = $r->param('id') !== null ? $r->intParam('id') : null;
        if ($id !== null && !$this->refs->exists($cfg['table'], $id)) {
            throw HttpException::notFound();
        }
        $back = '/admin/' . $kind . '/' . ($id ?? 'novo');
        $rules = ['name' => 'required|string|min:2|max:200', 'slug' => 'nullable|slug'];
        $rules += match ($cfg['table']) {
            'organizations' => [
                'acronym' => 'nullable|string|max:30',
                'sphere' => 'required|in:' . Vocabulary::keys(Vocabulary::SPHERES),
                'uf' => 'nullable|in:' . Vocabulary::keys(Vocabulary::UFS),
                'website_url' => 'nullable|url',
            ],
            'exam_boards' => ['acronym' => 'nullable|string|max:30', 'website_url' => 'nullable|url'],
            default => [
                'area' => 'required|in:' . Vocabulary::keys(Vocabulary::AREAS),
                'education_level' => 'required|in:' . Vocabulary::keys(Vocabulary::EDUCATION_LEVELS),
                'description' => 'nullable|string|max:2000',
            ],
        };
        $data = $this->validate($r, $rules, self::LABELS, $back);
        $data['slug'] ??= Format::slugify($data['name']);
        if ($data['slug'] === '') {
            throw new ValidationFailed(['slug' => ['Informe um endereço com letras ou números.']], $back, $r->only(array_keys($rules)));
        }
        try {
            $this->refs->save($cfg['table'], $id, $data);
        } catch (SlugTaken) {
            throw new ValidationFailed(['slug' => ['Esse endereço já está em uso; informe outro.']], $back, $r->only(array_keys($rules)));
        }
        $this->session->flash('success', 'Cadastro salvo.' . ($id !== null ? ' Concursos já publicados mostram o nome novo após a próxima publicação.' : ''));
        return Response::redirect('/admin/' . $kind);
    }

    /** @return array{0:string,1:array{table:string,title:string,new:string}} */
    private function kind(Request $r): array
    {
        $kind = (string) $r->param('kind');
        return isset(self::KINDS[$kind]) ? [$kind, self::KINDS[$kind]] : throw HttpException::notFound();
    }
}
