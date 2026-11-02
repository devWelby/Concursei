<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Http\HttpException;

/** Outra edição alterou o concurso depois que o formulário foi aberto (controle otimista). */
final class StaleVersion extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'conflict', 'Este concurso foi alterado por outra pessoa ou em outra aba depois que você abriu o formulário. Recarregue a página e refaça a alteração.');
    }
}
