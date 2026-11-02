<?php

declare(strict_types=1);

namespace Concursei\Modules\Home;

use Concursei\Core\Database\Database;
use Concursei\Core\Http\Controller;
use Concursei\Core\Http\Request;
use Concursei\Core\Http\Response;
use Concursei\Core\View\View;

final class HomeController extends Controller
{
    public function __construct(View $view, private Database $db)
    {
        parent::__construct($view);
    }

    public function index(Request $request): Response
    {
        return $this->render('home/index', ['title' => 'Concursos públicos, editais e estudo organizado']);
    }

    public function health(Request $request): Response
    {
        try {
            $this->db->scalar('SELECT 1');
            return Response::json(['status' => 'ok']);
        } catch (\Throwable) {
            return Response::json(['status' => 'degraded'], 503)->header('Retry-After', '30');
        }
    }
}
