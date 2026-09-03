-- EMCORE PI-led trade-document registry and secure file metadata.
--
-- Pre-deployment checks:
--   1. Confirm EMDEX 21 and EMDMET 44 are the correct next PI sequences.
--   2. Configure EMCORE_TRADE_STORAGE_ROOT outside the ProcessMaker web root.
--   3. Back up the database and the configured storage directory.

CREATE TABLE IF NOT EXISTS emcore_trade_issuers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    issuer_key VARCHAR(32) NOT NULL,
    name_fa VARCHAR(150) NOT NULL,
    name_en VARCHAR(150) NOT NULL,
    code_prefix VARCHAR(20) NOT NULL,
    next_sequence INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcore_trade_issuers_key (issuer_key),
    UNIQUE KEY uq_emcore_trade_issuers_prefix (code_prefix),
    KEY idx_emcore_trade_issuers_active (is_active, name_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_trade_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    issuer_id INT UNSIGNED NOT NULL,
    sequence_number INT UNSIGNED NOT NULL,
    number_year SMALLINT UNSIGNED NOT NULL,
    pi_number VARCHAR(64) NOT NULL,
    direction VARCHAR(16) NOT NULL DEFAULT 'export',
    summary VARCHAR(500) NOT NULL,
    counterparty VARCHAR(255) DEFAULT NULL,
    coordinator_usr_uid CHAR(32) NOT NULL,
    case_status VARCHAR(24) NOT NULL DEFAULT 'open',
    notes TEXT DEFAULT NULL,
    created_by_usr_uid CHAR(32) NOT NULL,
    updated_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcore_trade_cases_number (pi_number),
    UNIQUE KEY uq_emcore_trade_cases_issuer_sequence (issuer_id, sequence_number),
    KEY idx_emcore_trade_cases_active (deleted_at, case_status, updated_at),
    KEY idx_emcore_trade_cases_issuer (issuer_id, deleted_at, updated_at),
    KEY idx_emcore_trade_cases_coordinator (coordinator_usr_uid, deleted_at),
    CONSTRAINT fk_emcore_trade_cases_issuer
        FOREIGN KEY (issuer_id) REFERENCES emcore_trade_issuers(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_trade_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(8) NOT NULL,
    document_number VARCHAR(64) NOT NULL,
    document_date DATE DEFAULT NULL,
    document_status VARCHAR(24) NOT NULL DEFAULT 'not_started',
    approved_by_name VARCHAR(200) DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    status_note VARCHAR(1000) DEFAULT NULL,
    updated_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcore_trade_documents_case_type (case_id, document_type),
    UNIQUE KEY uq_emcore_trade_documents_number (document_number),
    KEY idx_emcore_trade_documents_status (document_type, document_status),
    CONSTRAINT fk_emcore_trade_documents_case
        FOREIGN KEY (case_id) REFERENCES emcore_trade_cases(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_trade_document_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    version_state VARCHAR(16) NOT NULL DEFAULT 'draft',
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(100) NOT NULL,
    storage_path VARCHAR(1000) NOT NULL,
    extension VARCHAR(10) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    change_note VARCHAR(1000) DEFAULT NULL,
    uploaded_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcore_trade_versions_revision (document_id, revision_number),
    KEY idx_emcore_trade_versions_active (document_id, deleted_at, revision_number),
    KEY idx_emcore_trade_versions_hash (sha256),
    CONSTRAINT fk_emcore_trade_versions_document
        FOREIGN KEY (document_id) REFERENCES emcore_trade_documents(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_trade_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    issuer_id INT UNSIGNED NOT NULL,
    document_type VARCHAR(8) NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(100) NOT NULL,
    storage_path VARCHAR(1000) NOT NULL,
    extension VARCHAR(10) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    template_note VARCHAR(1000) DEFAULT NULL,
    uploaded_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    superseded_at DATETIME DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcore_trade_templates_revision (issuer_id, document_type, revision_number),
    KEY idx_emcore_trade_templates_active (issuer_id, document_type, is_active, deleted_at),
    CONSTRAINT fk_emcore_trade_templates_issuer
        FOREIGN KEY (issuer_id) REFERENCES emcore_trade_issuers(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_trade_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'other',
    title VARCHAR(255) NOT NULL,
    reference_number VARCHAR(150) DEFAULT NULL,
    notes VARCHAR(1000) DEFAULT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(100) NOT NULL,
    storage_path VARCHAR(1000) NOT NULL,
    extension VARCHAR(10) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_emcore_trade_attachments_case (case_id, deleted_at, category, created_at),
    KEY idx_emcore_trade_attachments_hash (sha256),
    CONSTRAINT fk_emcore_trade_attachments_case
        FOREIGN KEY (case_id) REFERENCES emcore_trade_cases(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_trade_download_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_usr_uid CHAR(32) NOT NULL,
    file_kind VARCHAR(24) NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emcore_trade_download_actor (actor_usr_uid, created_at),
    KEY idx_emcore_trade_download_file (file_kind, file_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO emcore_trade_issuers
    (issuer_key, name_fa, name_en, code_prefix, next_sequence, is_active)
VALUES
    ('emidco', 'امیدکو', 'EMIDCO', 'EMDEX', 21, 1),
    ('emidco_metal', 'امیدکو متال', 'EMIDCO METAL', 'EMDMET', 44, 1)
ON DUPLICATE KEY UPDATE
    name_fa = VALUES(name_fa),
    name_en = VALUES(name_en),
    code_prefix = VALUES(code_prefix),
    is_active = 1;

INSERT INTO emcore_modules (module_key, name_fa, name_en, sort_order)
VALUES ('trade_documents', 'پرونده‌های بازرگانی', 'Trade documents', 140)
ON DUPLICATE KEY UPDATE
    name_fa = VALUES(name_fa),
    name_en = VALUES(name_en),
    sort_order = VALUES(sort_order),
    is_active = 1;

-- Existing authorization administrators receive full initial module access.
INSERT IGNORE INTO emcore_user_permissions
    (usr_uid, module_key, can_create, can_read, can_update, can_delete, granted_by)
SELECT p.usr_uid, 'trade_documents', 1, 1, 1, 1, p.usr_uid
FROM emcore_user_permissions p
JOIN USERS u ON u.USR_UID = p.usr_uid AND u.USR_STATUS = 'ACTIVE'
WHERE p.module_key = 'authorization' AND p.can_update = 1;
