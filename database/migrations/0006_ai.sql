-- Integração de IA (D-018): orçamento com reserva atômica, registro de chamadas sem conteúdo e circuit breaker.

-- Uma linha por período ("d:AAAA-MM-DD" e "m:AAAA-MM", no fuso de exibição).
-- Reserva antes da chamada (UPDATE condicional ao teto) e acerto pelo consumo informado depois.
CREATE TABLE ai_budget (
    period VARCHAR(16) NOT NULL PRIMARY KEY,
    reserved_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
    used_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
    calls INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metadados de cada chamada. Sem prompt, sem resposta, sem dados pessoais: só hash da entrada e contagens.
CREATE TABLE ai_calls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    purpose VARCHAR(40) NOT NULL,
    provider VARCHAR(20) NOT NULL,
    model VARCHAR(100) NOT NULL,
    prompt_version VARCHAR(40) NOT NULL,
    input_sha256 CHAR(64) NOT NULL,
    status ENUM('ok', 'failed') NOT NULL,
    error_kind VARCHAR(30) NULL,
    finish_reason VARCHAR(30) NULL,
    estimated_tokens INT UNSIGNED NOT NULL,
    prompt_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    total_tokens INT UNSIGNED NULL,
    latency_ms INT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_ai_calls_created (created_at),
    KEY idx_ai_calls_purpose (purpose, created_at),
    CONSTRAINT fk_ai_calls_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_circuit (
    provider VARCHAR(20) NOT NULL PRIMARY KEY,
    consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    open_until DATETIME NULL,
    last_failure_at DATETIME NULL,
    last_error_kind VARCHAR(30) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
