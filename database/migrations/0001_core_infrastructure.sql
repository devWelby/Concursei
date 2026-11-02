-- 0001 — Infraestrutura: sessões, rate limit, auditoria e configurações públicas.
-- Compatível com MariaDB 10.4+ e MySQL 5.7+/8.x (InnoDB, utf8mb4). Timestamps em UTC.

CREATE TABLE sessions (
    id_hash BINARY(32) NOT NULL PRIMARY KEY COMMENT 'SHA-256 do ID de sessão; o ID em claro nunca é armazenado',
    user_id BIGINT UNSIGNED NULL,
    data MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket BINARY(32) NOT NULL PRIMARY KEY COMMENT 'HMAC(escopo|identificador); IP não é armazenado em claro',
    scope VARCHAR(64) NOT NULL,
    hits INT UNSIGNED NOT NULL,
    window_start DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_rate_limits_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(64) NULL,
    target_id BIGINT UNSIGNED NULL,
    ip_hash BINARY(32) NULL COMMENT 'HMAC do IP (pseudonimizado)',
    request_id VARCHAR(32) NULL,
    meta LONGTEXT NULL COMMENT 'JSON sem dados pessoais/segredos, validado na aplicação',
    created_at DATETIME NOT NULL,
    KEY idx_audit_actor (actor_user_id, created_at),
    KEY idx_audit_target (target_type, target_id),
    KEY idx_audit_action (action, created_at),
    CONSTRAINT chk_audit_meta_json CHECK (meta IS NULL OR JSON_VALID(meta))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value LONGTEXT NOT NULL COMMENT 'JSON; somente configurações NÃO secretas (segredos ficam no .env privado)',
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT chk_settings_json CHECK (JSON_VALID(setting_value))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
