<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

/**
 * Executa UMA requisição já validada, sem seguir redirecionamentos (o cliente revalida cada salto).
 * Lança FetchFailed em erro de rede, tempo esgotado, tamanho excedido ou tipo não aceito.
 */
interface HttpTransport
{
    public function send(PinnedRequest $request): RawResponse;
}
