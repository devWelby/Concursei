-- 0002 — Usuários, tokens de autenticação, MFA, consentimentos e outbox de e-mail.
-- Contas não são apagadas fisicamente quando há histórico: são anonimizadas (ver docs/SECURITY.md).

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL COMMENT 'minúsculo; anonimizado na exclusão',
    email_verified_at DATETIME NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    role ENUM('student','editor','admin') NOT NULL DEFAULT 'student',
    status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
    mfa_secret_enc VARCHAR(255) NULL COMMENT 'semente TOTP cifrada (AES-256-GCM)',
    mfa_enabled_at DATETIME NULL,
    mfa_last_timestep BIGINT UNSIGNED NULL COMMENT 'anti-replay do TOTP',
    password_changed_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sessions
    ADD CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;

CREATE TABLE auth_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('email_verify','password_reset','email_change') NOT NULL,
    token_hash BINARY(32) NOT NULL COMMENT 'SHA-256; o token em claro só existe no link enviado',
    payload LONGTEXT NULL COMMENT 'JSON (ex.: novo e-mail na troca)',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user (user_id, purpose, used_at),
    KEY idx_auth_tokens_expires (expires_at),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_auth_tokens_payload CHECK (payload IS NULL OR JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mfa_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash BINARY(32) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_mfa_recovery_hash (code_hash),
    KEY idx_mfa_recovery_user (user_id, used_at),
    CONSTRAINT fk_mfa_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro append-only de consentimentos (estado atual = último registro por finalidade).
CREATE TABLE consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    subject_hash BINARY(32) NULL COMMENT 'HMAC do e-mail para assinantes sem conta',
    purpose ENUM('terms','privacy','age_18','alerts_email','marketing_email','analytics_cookies','ads_cookies') NOT NULL,
    version VARCHAR(20) NOT NULL,
    granted TINYINT(1) NOT NULL,
    source VARCHAR(40) NOT NULL,
    ip_hash BINARY(32) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_consents_user (user_id, purpose, id),
    KEY idx_consents_subject (subject_hash, purpose, id),
    CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbox de e-mail: gravado na mesma transação do evento; enviado inline (melhor esforço) ou pelo Cron.
-- Corpos são apagados após envio/falha definitiva (links com token não ficam retidos).
CREATE TABLE email_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    idempotency_key VARCHAR(190) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    to_email VARCHAR(254) NOT NULL,
    category ENUM('transactional','alert','marketing') NOT NULL,
    template VARCHAR(64) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    status ENUM('pending','sending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    lock_token CHAR(32) NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_outbox_idem (idempotency_key),
    KEY idx_outbox_dispatch (status, available_at),
    KEY idx_outbox_user (user_id),
    CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
