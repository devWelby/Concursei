<?php

declare(strict_types=1);

namespace Concursei\Modules\Auth;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;

/**
 * Direitos do titular (LGPD art. 18): cópia dos dados em formato legível por máquina e exclusão da conta.
 * A exclusão anonimiza a linha de users (o histórico de segurança, aceites e publicações da equipe
 * continua íntegro, mas sem identificar a pessoa) e apaga o restante dos dados pessoais.
 */
final class AccountDataService
{
    public const FORMAT = 'concursei-export-v1';
    private const HISTORY_LIMIT = 1000;

    public function __construct(private Database $db, private Clock $clock, private PasswordHasher $hasher)
    {
    }

    /** @return array<string,mixed> */
    public function export(int $userId): array
    {
        $user = $this->db->one(
            'SELECT email, display_name, role, status, timezone, email_verified_at, mfa_enabled_at, password_changed_at, last_login_at, created_at
               FROM users WHERE id = ?',
            [$userId]
        ) ?? throw new \RuntimeException('Conta inexistente.');
        $mfa = $user['mfa_enabled_at'] !== null;
        unset($user['mfa_enabled_at']);
        $limit = self::HISTORY_LIMIT;

        return [
            'formato' => self::FORMAT,
            'gerado_em_utc' => $this->clock->nowDb(),
            'observacao' => 'Datas em UTC. Exportação feita a pedido do titular (LGPD, art. 18).',
            'conta' => $user + ['verificacao_em_duas_etapas' => $mfa],
            'aceites' => $this->db->all('SELECT purpose AS finalidade, version AS versao, granted AS concedido, source AS origem, created_at AS registrado_em FROM consents WHERE user_id = ? ORDER BY id', [$userId]),
            'concursos_acompanhados' => $this->db->all(
                'SELECT COALESCE(cs.title, c.title) AS concurso, cs.slug AS endereco, f.created_at AS desde
                   FROM favorites f JOIN contests c ON c.id = f.contest_id LEFT JOIN contest_search cs ON cs.contest_id = f.contest_id
                  WHERE f.user_id = ? ORDER BY f.id',
                [$userId]
            ),
            'sessoes_ativas' => $this->db->all('SELECT created_at AS iniciada_em, last_activity_at AS ultima_atividade, expires_at AS expira_em FROM sessions WHERE user_id = ? ORDER BY created_at', [$userId]),
            'emails_enviados' => $this->db->all("SELECT template AS modelo, subject AS assunto, status AS situacao, created_at AS criado_em, sent_at AS enviado_em FROM email_outbox WHERE user_id = ? ORDER BY id DESC LIMIT {$limit}", [$userId]),
            'registro_de_seguranca' => $this->db->all(
                "SELECT action AS acao, target_type AS alvo, created_at AS em FROM audit_log
                  WHERE actor_user_id = ? OR (target_type = 'user' AND target_id = ?) ORDER BY id DESC LIMIT {$limit}",
                [$userId, $userId]
            ),
            'uso_de_ia' => $this->db->all("SELECT purpose AS finalidade, status AS situacao, total_tokens AS tokens, created_at AS em FROM ai_calls WHERE user_id = ? ORDER BY id DESC LIMIT {$limit}", [$userId]),
            'nao_incluido' => [
                'Senha, chave do autenticador e códigos de recuperação: guardados só em forma irreversível ou cifrada, por segurança.',
                'Endereços IP: o site guarda apenas códigos irreversíveis (HMAC), que não permitem recuperar o IP.',
                'Conteúdo dos e-mails: apagado logo após o envio.',
            ],
        ];
    }

    /**
     * Exclui a conta: anonimiza a identificação e apaga sessões, links pendentes, códigos de recuperação,
     * favoritos, jobs não iniciados e endereços/conteúdo de e-mails. Idempotente.
     */
    public function delete(int $userId): bool
    {
        return $this->db->transaction(function () use ($userId): bool {
            $now = $this->clock->nowDb();
            $n = $this->db->affected(
                "UPDATE users SET email = ?, email_verified_at = NULL, password_hash = ?, display_name = 'Conta excluída', role = 'student',
                        status = 'deleted', timezone = 'America/Sao_Paulo', mfa_secret_enc = NULL, mfa_enabled_at = NULL, mfa_last_timestep = NULL,
                        password_changed_at = NULL, last_login_at = NULL, deleted_at = ?, updated_at = ?
                  WHERE id = ? AND status <> 'deleted'",
                [self::anonymousEmail($userId), $this->hasher->hash(bin2hex(random_bytes(32))), $now, $now, $userId]
            );
            if ($n !== 1) {
                return false;
            }
            $this->db->run('DELETE FROM sessions WHERE user_id = ?', [$userId]);
            $this->db->run('DELETE FROM auth_tokens WHERE user_id = ?', [$userId]);
            $this->db->run('DELETE FROM mfa_recovery_codes WHERE user_id = ?', [$userId]);
            $this->db->run('DELETE FROM favorites WHERE user_id = ?', [$userId]);
            $this->db->run("DELETE FROM jobs WHERE owner_user_id = ? AND status IN ('pending', 'retry_scheduled')", [$userId]);
            $this->db->run("UPDATE email_outbox SET status = 'cancelled' WHERE user_id = ? AND status = 'pending'", [$userId]);
            $this->db->run("UPDATE email_outbox SET to_email = ?, body_text = '', body_html = '' WHERE user_id = ?", [self::anonymousEmail($userId), $userId]);
            return true;
        });
    }

    /** Endereço inutilizável (TLD reservado .invalid) que mantém a unicidade da coluna. */
    public static function anonymousEmail(int $userId): string
    {
        return 'excluido-' . $userId . '@conta-excluida.invalid';
    }
}
