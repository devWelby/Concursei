<?php

declare(strict_types=1);

namespace Concursei\Modules\Mail;

use Concursei\Core\Clock;
use Concursei\Core\Config;
use Concursei\Core\Database\Database;
use Concursei\Core\Logging\Logger;

/**
 * Outbox transacional de e-mail.
 * - enqueue() deve ser chamado na MESMA transação do evento (ex.: criação do token).
 * - Idempotência por chave única: retries não duplicam mensagens.
 * - Claim com lease + token de posse: worker antigo não sobrescreve o novo.
 * - Entrega "pelo menos uma vez": se o SMTP aceitar e a resposta se perder,
 *   pode haver reenvio (limitação documentada em docs/OPERATIONS.md).
 */
class Outbox
{
    private const LEASE_SECONDS = 120;

    public function __construct(
        private Database $db,
        private Clock $clock,
        private EmailRenderer $renderer,
        private Transport $transport,
        private Logger $logger,
        private Config $config,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->transport->isAvailable();
    }

    /** @param array<string,mixed> $vars @return int|null id criado, ou null se a chave já existia */
    public function enqueue(string $to, string $template, array $vars, string $idempotencyKey, ?int $userId = null, string $category = 'transactional'): ?int
    {
        $msg = $this->renderer->render($template, $vars);
        try {
            return $this->db->insert(
                'INSERT INTO email_outbox (idempotency_key, user_id, to_email, category, template, subject, body_text, body_html, status, attempts, available_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\', 0, ?, ?)',
                [substr($idempotencyKey, 0, 190), $userId, $to, $category, $template, $msg['subject'], $msg['text'], $msg['html'], $this->clock->nowDb(), $this->clock->nowDb()]
            );
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                return null;
            }
            throw $e;
        }
    }

    /** Tentativa imediata (melhor esforço, fora de transação). Falha fica para o Cron. */
    public function sendNow(?int $id): void
    {
        if ($id === null || !$this->transport->isAvailable() || $this->db->inTransaction()) {
            return;
        }
        foreach ($this->claim($id, 1) as $row) {
            $this->deliver($row);
        }
    }

    /** @return array{sent:int,retry:int,failed:int,skipped:bool} */
    public function dispatch(?int $limit = null): array
    {
        $stats = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => false];
        if (!$this->transport->isAvailable()) {
            $stats['skipped'] = true;
            return $stats;
        }
        foreach ($this->claim(null, $limit ?? $this->config->int('mail.batch_size', 20)) as $row) {
            $stats[$this->deliver($row)]++;
        }
        return $stats;
    }

    /** @return list<array<string,mixed>> */
    private function claim(?int $id, int $limit): array
    {
        $token = bin2hex(random_bytes(16));
        $now = $this->clock->nowDb();
        $sql = 'UPDATE email_outbox SET status = \'sending\', lock_token = ?, locked_until = ?, attempts = attempts + 1
                WHERE ((status = \'pending\' AND available_at <= ?) OR (status = \'sending\' AND locked_until < ?))'
            . ($id !== null ? ' AND id = ?' : '') . ' ORDER BY id LIMIT ' . max(1, min(200, $limit));
        $params = [$token, $this->clock->plusSecondsDb(self::LEASE_SECONDS), $now, $now];
        if ($id !== null) {
            $params[] = $id;
        }
        $this->db->run($sql, $params);
        return $this->db->all('SELECT * FROM email_outbox WHERE lock_token = ? AND status = \'sending\' ORDER BY id', [$token]);
    }

    /** @param array<string,mixed> $row @return 'sent'|'retry'|'failed' */
    private function deliver(array $row): string
    {
        try {
            $this->transport->send(new MailMessage(
                (string) $row['to_email'],
                (string) $row['subject'],
                (string) $row['body_text'],
                (string) $row['body_html'],
                (string) $row['idempotency_key'],
            ));
            $this->db->run(
                'UPDATE email_outbox SET status = \'sent\', sent_at = ?, body_text = \'\', body_html = \'\', lock_token = NULL, locked_until = NULL, last_error = NULL
                 WHERE id = ? AND lock_token = ?',
                [$this->clock->nowDb(), $row['id'], $row['lock_token']]
            );
            return 'sent';
        } catch (MailException $e) {
            $attempts = (int) $row['attempts'];
            $final = $attempts >= $this->config->int('mail.max_attempts', 5);
            $delay = min(3600, (int) (60 * (2 ** ($attempts - 1)))) + random_int(0, 30);
            if ($final) {
                $this->db->run(
                    'UPDATE email_outbox SET status = \'failed\', body_text = \'\', body_html = \'\', lock_token = NULL, locked_until = NULL, last_error = ? WHERE id = ? AND lock_token = ?',
                    [substr($e->getMessage(), 0, 255), $row['id'], $row['lock_token']]
                );
            } else {
                $this->db->run(
                    'UPDATE email_outbox SET status = \'pending\', available_at = ?, lock_token = NULL, locked_until = NULL, last_error = ? WHERE id = ? AND lock_token = ?',
                    [$this->clock->plusSecondsDb($delay), substr($e->getMessage(), 0, 255), $row['id'], $row['lock_token']]
                );
            }
            $this->logger->warning('E-mail não enviado', ['outbox_id' => $row['id'], 'template' => $row['template'], 'final' => $final]);
            return $final ? 'failed' : 'retry';
        }
    }

    /** Cancela pendentes de um destinatário (ex.: descadastro, exclusão de conta). */
    public function cancelPendingFor(string $email, ?string $category = null): int
    {
        $sql = 'UPDATE email_outbox SET status = \'cancelled\', body_text = \'\', body_html = \'\' WHERE to_email = ? AND status = \'pending\'';
        $params = [$email];
        if ($category !== null) {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        return $this->db->affected($sql, $params);
    }
}
