<?php

declare(strict_types=1);

namespace Concursei\Core\Http;

use RuntimeException;

/**
 * Erro HTTP com código estável e mensagem segura para o público.
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string,list<string>> $fieldErrors
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $publicMessage,
        public readonly array $fieldErrors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($publicMessage, $status);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'Página ou recurso não encontrado.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'forbidden', 'Você não tem permissão para esta ação.');
    }

    public static function unauthorized(): self
    {
        return new self(401, 'unauthenticated', 'Faça login para continuar.');
    }

    public static function conflict(string $message = 'O recurso foi alterado por outra operação. Recarregue e tente novamente.'): self
    {
        return new self(409, 'conflict', $message);
    }

    public static function tooManyRequests(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'Muitas tentativas. Aguarde e tente novamente.', [], ['Retry-After' => (string) max(1, $retryAfter)]);
    }

    /** @param array<string,list<string>> $errors */
    public static function validation(array $errors, string $message = 'Verifique os campos informados.'): self
    {
        return new self(422, 'validation_failed', $message, $errors);
    }
}
