-- Fila de jobs no banco e coordenação das tarefas agendadas pelo Cron (D-016).
-- Sem SKIP LOCKED (MariaDB 10.4 / MySQL 5.7): reserva por UPDATE ... LIMIT com token de posse.

CREATE TABLE jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(64) NOT NULL COMMENT 'allowlist em Jobs\\JobRegistry',
    payload TEXT NOT NULL COMMENT 'JSON pequeno; nunca segredos (referência por configuração)',
    status ENUM('pending', 'running', 'retry_scheduled', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    priority TINYINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'menor = executa antes',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    unique_key VARCHAR(190) NULL COMMENT 'deduplica enquanto o job está ativo; limpo ao terminar',
    owner_user_id BIGINT UNSIGNED NULL,
    available_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    lock_token CHAR(32) NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    last_error VARCHAR(500) NULL COMMENT 'mensagem redigida',
    result TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_jobs_unique_key (unique_key),
    KEY idx_jobs_claim (status, available_at, priority),
    KEY idx_jobs_lease (status, locked_until),
    KEY idx_jobs_type (type, status),
    KEY idx_jobs_owner (owner_user_id),
    KEY idx_jobs_finished (status, finished_at),
    CONSTRAINT fk_jobs_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_jobs_payload CHECK (JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma linha por tarefa agendada: lock de coordenação (sem sobreposição) e heartbeat para o painel de saúde.
CREATE TABLE scheduled_tasks (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    lock_token CHAR(32) NULL,
    locked_until DATETIME NULL,
    last_started_at DATETIME NULL,
    last_finished_at DATETIME NULL,
    last_status ENUM('running', 'ok', 'failed') NULL,
    last_duration_ms INT UNSIGNED NULL,
    last_message VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
