-- Extração assistida de editais (D-019). Resultado guardado já VALIDADO no PHP (nunca a resposta bruta da IA).
-- A IA só propõe: aplicar exige revisão humana, leva os itens à cópia de trabalho como rascunho
-- e nunca marca o concurso como conferido nem publica.

CREATE TABLE edital_extractions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    contest_id BIGINT UNSIGNED NULL COMMENT 'cópia de trabalho comparada e alterada na aplicação',
    status ENUM('queued', 'running', 'ready', 'failed', 'applied', 'rejected') NOT NULL DEFAULT 'queued',
    prompt_version VARCHAR(40) NOT NULL,
    input_sha256 CHAR(64) NOT NULL COMMENT 'versão do prompt + texto enviado: chave do cache',
    model VARCHAR(100) NULL,
    reused_from BIGINT UNSIGNED NULL COMMENT 'resultado reaproveitado de extração idêntica',
    result LONGTEXT NULL,
    error VARCHAR(255) NULL,
    requested_by BIGINT UNSIGNED NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_extractions_document (document_id, created_at),
    KEY idx_extractions_contest (contest_id, status),
    KEY idx_extractions_cache (input_sha256, status),
    CONSTRAINT fk_extractions_document FOREIGN KEY (document_id) REFERENCES source_documents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_extractions_contest FOREIGN KEY (contest_id) REFERENCES contests (id) ON DELETE SET NULL,
    CONSTRAINT fk_extractions_requester FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_extractions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_extractions_result CHECK (result IS NULL OR JSON_VALID(result))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
