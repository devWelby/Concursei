-- Catálogo de concursos (D-015).
-- Cópia de trabalho editável: organizations, exam_boards, careers, contests, contest_offers, contest_events.
-- Publicação: contest_revisions (snapshot imutável e histórico público) + modelo de leitura contest_search*.

CREATE TABLE organizations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL,
    name VARCHAR(200) NOT NULL,
    acronym VARCHAR(30) NULL,
    sphere ENUM('federal', 'estadual', 'municipal', 'distrital') NOT NULL,
    uf CHAR(2) NULL COMMENT 'NULL = órgão de abrangência nacional',
    website_url VARCHAR(2048) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_organizations_slug (slug),
    KEY idx_organizations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_boards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL,
    name VARCHAR(200) NOT NULL,
    acronym VARCHAR(30) NULL,
    website_url VARCHAR(2048) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_exam_boards_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE careers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL,
    name VARCHAR(200) NOT NULL,
    area VARCHAR(32) NOT NULL COMMENT 'vocabulário fixo em Catalog\\Vocabulary::AREAS',
    education_level ENUM('fundamental', 'medio', 'tecnico', 'superior') NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_careers_slug (slug),
    KEY idx_careers_area (area, education_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada linha é uma edição de concurso (ex.: "TRT-2 2027"), unidade seguida pelos usuários.
CREATE TABLE contests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    exam_board_id BIGINT UNSIGNED NULL COMMENT 'NULL = banca ainda não definida',
    title VARCHAR(200) NOT NULL,
    edition_year SMALLINT UNSIGNED NULL,
    uf CHAR(2) NULL COMMENT 'NULL = nacional ou várias UFs',
    status ENUM('predicted', 'authorized', 'board_defined', 'edital_published', 'suspended', 'cancelled', 'concluded') NOT NULL,
    summary TEXT NULL,
    fee_cents INT UNSIGNED NULL COMMENT 'taxa única/maior; NULL = não informada',
    fee_note VARCHAR(500) NULL COMMENT 'taxas por cargo, isenção',
    official_url VARCHAR(2048) NULL,
    registration_url VARCHAR(2048) NULL,
    source_url VARCHAR(2048) NULL COMMENT 'documento oficial usado na conferência',
    source_published_on DATE NULL,
    workflow_status ENUM('draft', 'in_review', 'published') NOT NULL DEFAULT 'draft'
        COMMENT 'estado da cópia de trabalho; published = idêntica à revisão pública',
    version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'controle otimista de edição',
    published_revision_id BIGINT UNSIGNED NULL COMMENT 'revisão exibida ao público',
    archived_at DATETIME NULL COMMENT 'retirado do catálogo público',
    last_verified_at DATETIME NULL COMMENT 'última conferência humana com a fonte oficial',
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    submitted_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_contests_slug (slug),
    KEY idx_contests_workflow (workflow_status, updated_at),
    KEY idx_contests_org (organization_id),
    KEY idx_contests_board (exam_board_id),
    CONSTRAINT fk_contests_org FOREIGN KEY (organization_id) REFERENCES organizations (id),
    CONSTRAINT fk_contests_board FOREIGN KEY (exam_board_id) REFERENCES exam_boards (id),
    CONSTRAINT fk_contests_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_contests_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_contests_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Oferta de cargo dentro de uma edição. vacancies NULL = não informado; reserve = cadastro de reserva.
CREATE TABLE contest_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contest_id BIGINT UNSIGNED NOT NULL,
    career_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL COMMENT 'nome do cargo como no edital',
    education_level ENUM('fundamental', 'medio', 'tecnico', 'superior') NOT NULL,
    vacancies INT UNSIGNED NULL,
    reserve TINYINT(1) NOT NULL DEFAULT 0,
    salary_cents INT UNSIGNED NULL,
    salary_note VARCHAR(500) NULL COMMENT 'composição da remuneração',
    workload_hours TINYINT UNSIGNED NULL COMMENT 'horas semanais',
    requirements TEXT NULL,
    location VARCHAR(200) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_offers_contest (contest_id, sort_order),
    KEY idx_offers_career (career_id),
    CONSTRAINT fk_offers_contest FOREIGN KEY (contest_id) REFERENCES contests (id) ON DELETE CASCADE,
    CONSTRAINT fk_offers_career FOREIGN KEY (career_id) REFERENCES careers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datas com precisão explícita: data desconhecida fica NULL com precisão 'unknown';
-- 'month'/'year' guardam o primeiro dia do período e nunca são exibidas como dia exato.
CREATE TABLE contest_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contest_id BIGINT UNSIGNED NOT NULL,
    type ENUM('authorization', 'board_defined', 'edital', 'registration_start', 'registration_end',
              'fee_exemption_deadline', 'exam', 'answer_key', 'result', 'other') NOT NULL,
    label VARCHAR(200) NULL,
    date_value DATE NULL,
    date_precision ENUM('day', 'month', 'year', 'unknown') NOT NULL,
    time_local TIME NULL COMMENT 'horário de Brasília quando informado',
    note VARCHAR(300) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_events_contest (contest_id, date_value),
    CONSTRAINT fk_events_contest FOREIGN KEY (contest_id) REFERENCES contests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Revisões publicadas: imutáveis, nunca apagadas em cascata (FK sem CASCADE).
CREATE TABLE contest_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contest_id BIGINT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    snapshot LONGTEXT NOT NULL,
    change_note VARCHAR(500) NULL COMMENT 'o que mudou (exibido no histórico público)',
    is_correction TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'corrige informação publicada anteriormente',
    published_by BIGINT UNSIGNED NULL,
    published_at DATETIME NOT NULL,
    UNIQUE KEY uq_revisions_contest_no (contest_id, revision_no),
    KEY idx_revisions_published (published_at),
    CONSTRAINT fk_revisions_contest FOREIGN KEY (contest_id) REFERENCES contests (id),
    CONSTRAINT fk_revisions_user FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_revisions_snapshot CHECK (JSON_VALID(snapshot))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contests
    ADD CONSTRAINT fk_contests_revision FOREIGN KEY (published_revision_id) REFERENCES contest_revisions (id);

-- Modelo de leitura público (reconstruído a cada publicação/arquivamento; fase recalculada pelo Cron).
CREATE TABLE contest_search (
    contest_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    slug VARCHAR(190) NOT NULL,
    title VARCHAR(200) NOT NULL,
    organization_name VARCHAR(200) NOT NULL,
    organization_slug VARCHAR(190) NOT NULL,
    organization_acronym VARCHAR(30) NULL,
    exam_board_name VARCHAR(200) NULL,
    exam_board_slug VARCHAR(190) NULL,
    uf CHAR(2) NULL,
    region VARCHAR(16) NULL,
    status VARCHAR(32) NOT NULL,
    phase VARCHAR(32) NOT NULL,
    next_event_on DATE NULL,
    next_event_type VARCHAR(32) NULL,
    registration_ends_on DATE NULL,
    vacancies_total INT UNSIGNED NULL COMMENT 'soma das vagas informadas; NULL = nenhuma informada',
    has_reserve TINYINT(1) NOT NULL DEFAULT 0,
    salary_max_cents INT UNSIGNED NULL,
    published_at DATETIME NOT NULL,
    refreshed_on DATE NOT NULL,
    UNIQUE KEY uq_search_slug (slug),
    KEY idx_search_phase (phase, registration_ends_on),
    KEY idx_search_uf (uf),
    KEY idx_search_region (region),
    KEY idx_search_org (organization_slug),
    KEY idx_search_board (exam_board_slug),
    KEY idx_search_published (published_at),
    KEY idx_search_next (next_event_on),
    CONSTRAINT fk_search_contest FOREIGN KEY (contest_id) REFERENCES contests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contest_search_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contest_id BIGINT UNSIGNED NOT NULL,
    career_slug VARCHAR(190) NULL,
    area VARCHAR(32) NULL,
    education_level VARCHAR(16) NOT NULL,
    title VARCHAR(200) NOT NULL,
    KEY idx_search_offers_contest (contest_id),
    KEY idx_search_offers_area (area, contest_id),
    KEY idx_search_offers_level (education_level, contest_id),
    KEY idx_search_offers_career (career_slug, contest_id),
    CONSTRAINT fk_search_offers_contest FOREIGN KEY (contest_id) REFERENCES contest_search (contest_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contest_search_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contest_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(32) NOT NULL,
    label VARCHAR(200) NULL,
    date_value DATE NULL,
    date_precision VARCHAR(8) NOT NULL,
    time_local TIME NULL,
    KEY idx_search_events_date (date_precision, date_value),
    KEY idx_search_events_contest (contest_id),
    CONSTRAINT fk_search_events_contest FOREIGN KEY (contest_id) REFERENCES contest_search (contest_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Favoritos: um por usuário/concurso; removidos com a conta (dado pessoal).
CREATE TABLE favorites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    contest_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_favorites_user_contest (user_id, contest_id),
    KEY idx_favorites_contest (contest_id),
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_contest FOREIGN KEY (contest_id) REFERENCES contests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
