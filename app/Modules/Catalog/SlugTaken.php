<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

/** Endereço (slug) já usado por outro registro da mesma tabela. */
final class SlugTaken extends \RuntimeException
{
}
