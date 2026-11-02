<?php

declare(strict_types=1);

namespace Concursei\Core\Http;

/**
 * Falha de validação. Em HTML, o kernel redireciona para $back com os erros e a
 * entrada anterior (sem senhas); em JSON, responde 422 com erros por campo.
 */
final class ValidationFailed extends HttpException
{
    /**
     * @param array<string,list<string>> $errors
     * @param array<string,mixed> $input
     */
    public function __construct(array $errors, public readonly ?string $back = null, public readonly array $input = [], string $message = 'Verifique os campos informados.')
    {
        parent::__construct(422, 'validation_failed', $message, $errors);
    }
}
