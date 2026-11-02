<?php

declare(strict_types=1);

namespace Concursei\Shared\Audit;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;
use Concursei\Core\Logging\Logger;
use Concursei\Core\Security\Crypto;

/**
 * Trilha de auditoria de ações sensíveis. Metadados passam pela redação do
 * Logger (sem senhas/tokens/e-mails completos); IP é pseudonimizado (HMAC).
 */
class AuditLog
{
    public function __construct(private Database $db, private Clock $clock, private Crypto $crypto, private Logger $logger)
    {
    }

    /** @param array<string,mixed> $meta */
    public function record(?int $actorId, string $action, ?string $targetType = null, ?int $targetId = null, array $meta = [], ?string $ip = null): void
    {
        $this->db->run(
            'INSERT INTO audit_log (actor_user_id, action, target_type, target_id, ip_hash, request_id, meta, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $actorId,
                substr($action, 0, 64),
                $targetType,
                $targetId,
                $ip !== null ? $this->crypto->mac('ip', $ip) : null,
                $this->logger->requestId(),
                $meta === [] ? null : json_encode(Logger::redact($meta), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $this->clock->nowDb(),
            ]
        );
    }
}
