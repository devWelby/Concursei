-- Registro de fontes e documentos coletados (D-017).
-- Documentos são evidência: guardados fora do webroot (storage/private/sources), identificados por hash.

CREATE TABLE sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    kind ENUM('official', 'secondary') NOT NULL COMMENT 'secundária serve para descoberta, nunca confirma dado',
    method ENUM('page', 'document') NOT NULL COMMENT 'page = página monitorada (descobre PDFs); document = arquivo direto',
    url VARCHAR(2048) NOT NULL,
    extra_hosts VARCHAR(500) NULL COMMENT 'hosts adicionais autorizados (ex.: *.cdn.gov.br), separados por vírgula',
    organization_id BIGINT UNSIGNED NULL,
    exam_board_id BIGINT UNSIGNED NULL,
    contest_id BIGINT UNSIGNED NULL COMMENT 'concurso acompanhado por esta fonte, se houver',
    check_every_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    terms_note VARCHAR(500) NULL COMMENT 'condições de uso observadas pela equipe',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    health ENUM('unknown', 'ok', 'failing', 'blocked') NOT NULL DEFAULT 'unknown',
    consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    etag VARCHAR(255) NULL,
    last_modified VARCHAR(64) NULL,
    last_hash CHAR(64) NULL,
    last_checked_at DATETIME NULL,
    last_changed_at DATETIME NULL,
    next_check_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_sources_due (enabled, next_check_at),
    KEY idx_sources_contest (contest_id),
    CONSTRAINT fk_sources_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_sources_board FOREIGN KEY (exam_board_id) REFERENCES exam_boards (id) ON DELETE SET NULL,
    CONSTRAINT fk_sources_contest FOREIGN KEY (contest_id) REFERENCES contests (id) ON DELETE SET NULL,
    CONSTRAINT fk_sources_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE source_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    url VARCHAR(2048) NOT NULL COMMENT 'URL final efetivamente recuperada',
    sha256 CHAR(64) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    storage_path VARCHAR(255) NOT NULL COMMENT 'relativo a storage/private/sources',
    title VARCHAR(300) NULL,
    fetched_at DATETIME NOT NULL,
    text_status ENUM('pending', 'ready', 'empty', 'unsupported', 'failed') NOT NULL DEFAULT 'pending'
        COMMENT 'empty = sem texto extraível (provável digitalização): revisão manual',
    text_note VARCHAR(255) NULL,
    page_count SMALLINT UNSIGNED NULL,
    text_chars INT UNSIGNED NULL,
    text MEDIUMTEXT NULL,
    UNIQUE KEY uq_source_documents_hash (source_id, sha256),
    KEY idx_source_documents_fetched (source_id, fetched_at),
    KEY idx_source_documents_status (text_status),
    CONSTRAINT fk_source_documents_source FOREIGN KEY (source_id) REFERENCES sources (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
