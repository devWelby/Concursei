<?php

declare(strict_types=1);

namespace Concursei\Modules\Jobs;

/** Job reservado por um worker. O token de posse condiciona toda finalização. */
final class Job
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?int $ownerUserId,
        public readonly string $lockToken,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $payload = json_decode((string) $row['payload'], true);
        return new self(
            (int) $row['id'],
            (string) $row['type'],
            is_array($payload) ? $payload : [],
            (int) $row['attempts'],
            (int) $row['max_attempts'],
            $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
            (string) $row['lock_token'],
        );
    }

    public function int(string $key): int
    {
        $v = $this->payload[$key] ?? null;
        if (!is_int($v) || $v < 1) {
            throw new JobFailed("Payload inválido: {$key}");
        }
        return $v;
    }
}
