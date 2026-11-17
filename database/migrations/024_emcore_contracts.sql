-- Requires migrations 001 and 002. Re-runnable. No permission grants.
INSERT IGNORE INTO emcore_modules (module_key,name_fa,name_en,sort_order)
VALUES ('contracts','قراردادها','Contracts',150);

CREATE TABLE IF NOT EXISTS emcore_contracts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(250) NOT NULL,
    official_number VARCHAR(100) DEFAULT NULL,
    contract_type VARCHAR(80) DEFAULT NULL,
    subject VARCHAR(500) DEFAULT NULL,
    description TEXT,
    owner_uid CHAR(32) NOT NULL,
    unit_name VARCHAR(150) DEFAULT NULL,
    project_name VARCHAR(200) DEFAULT NULL,
    site_name VARCHAR(200) DEFAULT NULL,
    signed_fa CHAR(10) DEFAULT NULL, signed_on DATE DEFAULT NULL,
    starts_fa CHAR(10) DEFAULT NULL, starts_on DATE DEFAULT NULL,
    ends_fa CHAR(10) DEFAULT NULL, ends_on DATE DEFAULT NULL,
    notice_fa CHAR(10) DEFAULT NULL, notice_on DATE DEFAULT NULL,
    legal_status VARCHAR(24) NOT NULL DEFAULT 'unknown',
    signature_status VARCHAR(24) NOT NULL DEFAULT 'unknown',
    review_status VARCHAR(24) NOT NULL DEFAULT 'needs_review',
    basis_version_id BIGINT UNSIGNED DEFAULT NULL,
    amount_type VARCHAR(24) NOT NULL DEFAULT 'unknown',
    amount DECIMAL(24,4) DEFAULT NULL,
    currency VARCHAR(12) DEFAULT NULL,
    quantity_unit VARCHAR(80) DEFAULT NULL,
    tax_note VARCHAR(500) DEFAULT NULL,
    payment_terms TEXT,
    alert_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    original_location VARCHAR(255) DEFAULT NULL,
    archive_code VARCHAR(100) DEFAULT NULL,
    custodian VARCHAR(150) DEFAULT NULL,
    original_status VARCHAR(24) NOT NULL DEFAULT 'unknown',
    archive_notes TEXT,
    retention_review_fa CHAR(10) DEFAULT NULL, retention_review_on DATE DEFAULT NULL,
    hold_reason VARCHAR(500) DEFAULT NULL,
    hold_by CHAR(32) DEFAULT NULL,
    hold_at DATETIME DEFAULT NULL,
    archived_at DATETIME DEFAULT NULL,
    merged_into BIGINT UNSIGNED DEFAULT NULL,
    origin_bundle VARCHAR(60) DEFAULT NULL UNIQUE,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by CHAR(32) NOT NULL, updated_by CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    deleted_at DATETIME DEFAULT NULL, deleted_by CHAR(32) DEFAULT NULL, delete_reason VARCHAR(500) DEFAULT NULL,
    KEY contract_review (deleted_at,merged_into,review_status,id),
    KEY contract_number (official_number),
    KEY contract_ends (deleted_at,ends_on),
    KEY contract_notice (deleted_at,notice_on),
    FOREIGN KEY (merged_into) REFERENCES emcore_contracts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_parties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL,
    side VARCHAR(12) NOT NULL, kind VARCHAR(12) NOT NULL,
    reference_id BIGINT UNSIGNED DEFAULT NULL,
    name VARCHAR(250) NOT NULL, role_name VARCHAR(100) DEFAULT NULL,
    national_id VARCHAR(50) DEFAULT NULL, registration_number VARCHAR(50) DEFAULT NULL,
    address VARCHAR(500) DEFAULT NULL,
    KEY (contract_id),
    FOREIGN KEY (contract_id) REFERENCES emcore_contracts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_mines (
    contract_id BIGINT UNSIGNED NOT NULL, mine_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (contract_id,mine_id),
    FOREIGN KEY (contract_id) REFERENCES emcore_contracts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED DEFAULT NULL,
    scope_key BIGINT UNSIGNED AS (IFNULL(contract_id,0)) STORED,
    title VARCHAR(160) NOT NULL, title_key VARCHAR(160) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by CHAR(32) DEFAULT NULL, updated_by CHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    UNIQUE KEY category_title (scope_key,title_key),
    FOREIGN KEY (contract_id) REFERENCES emcore_contracts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO emcore_contract_categories (contract_id,title,title_key,sort_order,created_at,updated_at)
SELECT NULL,seed.title,seed.title_key,seed.sort_order,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM (
    SELECT 'تمدید و مهلت اخطار' AS title,'تمدیدومهلتاخطار' AS title_key,10 AS sort_order
    UNION ALL SELECT 'الحاقیه و اصلاح قرارداد','الحاقیهواصلاحقرارداد',20
    UNION ALL SELECT 'پیگیری و مکاتبات','پیگیریومکاتبات',30
    UNION ALL SELECT 'تعهدات و تحویل‌ها','تعهداتوتحویلها',40
    UNION ALL SELECT 'تضمین‌ها و بیمه','تضمینهاوبیمه',50
    UNION ALL SELECT 'تسویه و خاتمه','تسویهوخاتمه',60
    UNION ALL SELECT 'سایر امور','سایرامور',70
) seed
WHERE NOT EXISTS (SELECT 1 FROM emcore_contract_categories WHERE contract_id IS NULL);

CREATE TABLE IF NOT EXISTS emcore_contract_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(250) NOT NULL, role_code VARCHAR(32) NOT NULL,
    signature_status VARCHAR(24) NOT NULL DEFAULT 'unknown',
    current_version_id BIGINT UNSIGNED DEFAULT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by CHAR(32) NOT NULL, updated_by CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    KEY (contract_id,id),
    FOREIGN KEY (contract_id) REFERENCES emcore_contracts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL, version_no INT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL, storage_key VARCHAR(80) NOT NULL UNIQUE,
    mime_type VARCHAR(150) NOT NULL, file_size BIGINT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL,
    change_note VARCHAR(500) DEFAULT NULL,
    extracted_text MEDIUMTEXT,
    created_by CHAR(32) NOT NULL, created_at DATETIME NOT NULL,
    deleted_at DATETIME DEFAULT NULL, deleted_by CHAR(32) DEFAULT NULL, delete_reason VARCHAR(500) DEFAULT NULL,
    UNIQUE KEY (document_id,version_no),
    FOREIGN KEY (document_id) REFERENCES emcore_contract_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_amendments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL, basis_version_id BIGINT UNSIGNED NOT NULL,
    corrects_id BIGINT UNSIGNED DEFAULT NULL, number VARCHAR(100) DEFAULT NULL,
    signed_fa CHAR(10) DEFAULT NULL, signed_on DATE DEFAULT NULL,
    effective_fa CHAR(10) NOT NULL, effective_on DATE NOT NULL,
    description TEXT NOT NULL, proposed TEXT NOT NULL, previous_values TEXT,
    applied_by CHAR(32) DEFAULT NULL, applied_at DATETIME DEFAULT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by CHAR(32) NOT NULL, updated_by CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    KEY (contract_id,id),
    FOREIGN KEY (contract_id) REFERENCES emcore_contracts(id),
    FOREIGN KEY (basis_version_id) REFERENCES emcore_contract_versions(id),
    FOREIGN KEY (corrects_id) REFERENCES emcore_contract_amendments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_obligations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(16) NOT NULL,
    title VARCHAR(250) NOT NULL, owner_uid CHAR(32) DEFAULT NULL,
    party_id BIGINT UNSIGNED DEFAULT NULL, issuer VARCHAR(250) DEFAULT NULL,
    number VARCHAR(100) DEFAULT NULL, amount DECIMAL(24,4) DEFAULT NULL, currency VARCHAR(12) DEFAULT NULL,
    due_fa CHAR(10) DEFAULT NULL, due_on DATE DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open', notes TEXT,
    document_id BIGINT UNSIGNED DEFAULT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by CHAR(32) NOT NULL, updated_by CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    deleted_at DATETIME DEFAULT NULL, deleted_by CHAR(32) DEFAULT NULL, delete_reason VARCHAR(500) DEFAULT NULL,
    KEY (contract_id,deleted_at,id), KEY (status,deleted_at,due_on),
    FOREIGN KEY (contract_id) REFERENCES emcore_contracts(id),
    FOREIGN KEY (party_id) REFERENCES emcore_contract_parties(id),
    FOREIGN KEY (document_id) REFERENCES emcore_contract_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL, category_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(250) NOT NULL, description TEXT, owner_uid CHAR(32) NOT NULL,
    due_fa CHAR(10) DEFAULT NULL, due_on DATE DEFAULT NULL,
    priority VARCHAR(12) NOT NULL DEFAULT 'normal', status VARCHAR(20) NOT NULL DEFAULT 'open',
    completed_fa CHAR(10) DEFAULT NULL, completed_on DATE DEFAULT NULL, result TEXT,
    document_id BIGINT UNSIGNED DEFAULT NULL, amendment_id BIGINT UNSIGNED DEFAULT NULL,
    obligation_id BIGINT UNSIGNED DEFAULT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by CHAR(32) NOT NULL, updated_by CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    KEY (contract_id,status,due_on,id), KEY (owner_uid,status,due_on,id), KEY (status,due_on),
    FOREIGN KEY (contract_id) REFERENCES emcore_contracts(id),
    FOREIGN KEY (category_id) REFERENCES emcore_contract_categories(id),
    FOREIGN KEY (document_id) REFERENCES emcore_contract_documents(id),
    FOREIGN KEY (amendment_id) REFERENCES emcore_contract_amendments(id),
    FOREIGN KEY (obligation_id) REFERENCES emcore_contract_obligations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL, category_id BIGINT UNSIGNED NOT NULL,
    action_id BIGINT UNSIGNED DEFAULT NULL, document_id BIGINT UNSIGNED DEFAULT NULL,
    description TEXT NOT NULL, happened_fa CHAR(10) NOT NULL, happened_on DATE NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by CHAR(32) NOT NULL, updated_by CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    deleted_at DATETIME DEFAULT NULL, deleted_by CHAR(32) DEFAULT NULL, delete_reason VARCHAR(500) DEFAULT NULL,
    KEY (contract_id,deleted_at,happened_on,id),
    FOREIGN KEY (contract_id) REFERENCES emcore_contracts(id),
    FOREIGN KEY (category_id) REFERENCES emcore_contract_categories(id),
    FOREIGN KEY (action_id) REFERENCES emcore_contract_actions(id),
    FOREIGN KEY (document_id) REFERENCES emcore_contract_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_evidence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL, field_name VARCHAR(40) NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL, locator VARCHAR(200) NOT NULL,
    raw_value TEXT, verified_value TEXT, outcome VARCHAR(20) NOT NULL,
    created_by CHAR(32) NOT NULL, created_at DATETIME NOT NULL,
    KEY (contract_id,field_name,id),
    FOREIGN KEY (contract_id) REFERENCES emcore_contracts(id),
    FOREIGN KEY (version_id) REFERENCES emcore_contract_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_import_batches (
    manifest_hash CHAR(64) PRIMARY KEY, total INT UNSIGNED NOT NULL, next_index INT UNSIGNED NOT NULL DEFAULT 0,
    created_by CHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id VARCHAR(40) NOT NULL UNIQUE, bundle_id VARCHAR(40) DEFAULT NULL,
    manifest_hash CHAR(64) NOT NULL, relative_path TEXT NOT NULL, sha256 CHAR(64) DEFAULT NULL,
    status VARCHAR(24) NOT NULL, metadata MEDIUMTEXT NOT NULL,
    version_id BIGINT UNSIGNED DEFAULT NULL, storage_key VARCHAR(80) DEFAULT NULL,
    created_by CHAR(32) NOT NULL, created_at DATETIME NOT NULL,
    KEY (manifest_hash,status),
    FOREIGN KEY (manifest_hash) REFERENCES emcore_contract_import_batches(manifest_hash),
    FOREIGN KEY (version_id) REFERENCES emcore_contract_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_commands (
    actor_uid CHAR(32) NOT NULL, request_id CHAR(32) NOT NULL,
    fingerprint CHAR(64) NOT NULL, result MEDIUMTEXT,
    created_at DATETIME NOT NULL, PRIMARY KEY (actor_uid,request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_contract_downloads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED DEFAULT NULL, version_id BIGINT UNSIGNED DEFAULT NULL,
    actor_uid CHAR(32) NOT NULL, kind VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL,
    KEY (contract_id,created_at),
    FOREIGN KEY (contract_id) REFERENCES emcore_contracts(id),
    FOREIGN KEY (version_id) REFERENCES emcore_contract_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
