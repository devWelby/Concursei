<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

interface Resolver
{
    /** @return list<string> todos os endereços A/AAAA do host (vazio se não resolver) */
    public function resolve(string $host): array;
}
