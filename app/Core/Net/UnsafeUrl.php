<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

use RuntimeException;

/** Destino recusado pela política do coletor (definitivo: repetir não muda o resultado). */
final class UnsafeUrl extends RuntimeException
{
}
