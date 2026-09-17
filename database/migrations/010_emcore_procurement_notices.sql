-- Replace the legacy ProcessMaker/XCRUD tender and auction register with a
-- secured, auditable EMCORE module. Legacy source values remain traceable;
-- calculated deadline state is intentionally not stored.

CREATE TABLE IF NOT EXISTS emcore_procurement_import_batches (
    batch_id CHAR(32) NOT NULL,
    source_name VARCHAR(500) NOT NULL,
    actor_usr_uid CHAR(32) NOT NULL,
    source_row_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
    review_count INT UNSIGNED NOT NULL DEFAULT 0,
    summary_json JSON DEFAULT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (batch_id),
    KEY idx_emcore_procurement_import_actor (actor_usr_uid, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_procurement_notices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    legacy_source_id INT UNSIGNED DEFAULT NULL,
    legacy_import_batch_id CHAR(32) DEFAULT NULL,
    record_origin ENUM('managed', 'legacy') NOT NULL DEFAULT 'managed',
    notice_type ENUM('tender', 'auction', 'unknown') NOT NULL,
    source_name VARCHAR(255) DEFAULT NULL,
    supplier_name VARCHAR(255) DEFAULT NULL,
    title VARCHAR(1000) NOT NULL,
    quantity_text VARCHAR(255) DEFAULT NULL,
    reference_number VARCHAR(255) DEFAULT NULL,
    amount_text VARCHAR(255) DEFAULT NULL,
    currency VARCHAR(32) DEFAULT NULL,
    contracting_authority VARCHAR(255) DEFAULT NULL,
    submission_method ENUM('physical', 'online', 'other') DEFAULT NULL,
    delivery_term VARCHAR(32) DEFAULT NULL,
    primary_guarantee VARCHAR(255) DEFAULT NULL,
    secondary_guarantee VARCHAR(255) DEFAULT NULL,
    registered_on_fa VARCHAR(10) DEFAULT NULL,
    registered_on_en DATE DEFAULT NULL,
    documents_deadline_fa VARCHAR(10) DEFAULT NULL,
    documents_deadline_en DATE DEFAULT NULL,
    response_deadline_fa VARCHAR(10) DEFAULT NULL,
    response_deadline_en DATE DEFAULT NULL,
    alert_lead_days SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    responsible_unit VARCHAR(64) DEFAULT NULL,
    category_name VARCHAR(255) DEFAULT NULL,
    subcategory_name VARCHAR(255) DEFAULT NULL,
    product_name VARCHAR(255) DEFAULT NULL,
    drilling_area VARCHAR(2000) DEFAULT NULL,
    participation_status ENUM(
        'registered', 'interested', 'documents_submitted', 'won', 'lost', 'unknown'
    ) NOT NULL DEFAULT 'registered',
    interest_reason VARCHAR(5000) DEFAULT NULL,
    is_extended TINYINT(1) DEFAULT NULL,
    legacy_source_data JSON DEFAULT NULL,
    created_by_usr_uid CHAR(32) NOT NULL,
    updated_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcore_procurement_legacy_id (legacy_source_id),
    KEY idx_emcore_procurement_deadline (response_deadline_en, deleted_at),
    KEY idx_emcore_procurement_type_status (notice_type, participation_status, deleted_at),
    KEY idx_emcore_procurement_unit (responsible_unit, deleted_at),
    KEY idx_emcore_procurement_source (source_name, deleted_at),
    KEY idx_emcore_procurement_batch (legacy_import_batch_id),
    CONSTRAINT fk_emcore_procurement_import_batch
        FOREIGN KEY (legacy_import_batch_id)
        REFERENCES emcore_procurement_import_batches (batch_id),
    CONSTRAINT chk_emcore_procurement_alert_days
        CHECK (alert_lead_days <= 365),
    CONSTRAINT chk_emcore_procurement_lock_version
        CHECK (lock_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_procurement_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    procurement_id BIGINT UNSIGNED NOT NULL,
    file_role ENUM('notice_document', 'final_submission') NOT NULL,
    record_origin ENUM('managed', 'legacy_reference') NOT NULL DEFAULT 'managed',
    original_filename VARCHAR(255) DEFAULT NULL,
    stored_filename VARCHAR(255) DEFAULT NULL,
    storage_path VARCHAR(1000) DEFAULT NULL,
    extension VARCHAR(20) DEFAULT NULL,
    mime_type VARCHAR(150) DEFAULT NULL,
    file_size BIGINT UNSIGNED DEFAULT NULL,
    sha256 CHAR(64) DEFAULT NULL,
    legacy_reference VARCHAR(255) DEFAULT NULL,
    uploaded_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_emcore_procurement_files_notice (procurement_id, file_role, deleted_at),
    CONSTRAINT fk_emcore_procurement_files_notice
        FOREIGN KEY (procurement_id)
        REFERENCES emcore_procurement_notices (id),
    CONSTRAINT chk_emcore_procurement_file_payload
        CHECK (
            (record_origin = 'managed'
                AND original_filename IS NOT NULL
                AND stored_filename IS NOT NULL
                AND storage_path IS NOT NULL
                AND extension IS NOT NULL
                AND mime_type IS NOT NULL
                AND file_size IS NOT NULL
                AND sha256 IS NOT NULL)
            OR
            (record_origin = 'legacy_reference' AND legacy_reference IS NOT NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_procurement_download_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_usr_uid CHAR(32) NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emcore_procurement_download_file (file_id, downloaded_at),
    KEY idx_emcore_procurement_download_actor (actor_usr_uid, downloaded_at),
    CONSTRAINT fk_emcore_procurement_download_file
        FOREIGN KEY (file_id)
        REFERENCES emcore_procurement_files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO emcore_modules (module_key, name_fa, name_en, sort_order)
VALUES ('procurement_notices', 'مناقصات و مزایدات', 'Tenders and auctions', 150)
ON DUPLICATE KEY UPDATE
    name_fa = VALUES(name_fa),
    name_en = VALUES(name_en),
    sort_order = VALUES(sort_order),
    is_active = 1;

-- Existing authorization administrators receive full initial module access.
INSERT IGNORE INTO emcore_user_permissions
    (usr_uid, module_key, can_create, can_read, can_update, can_delete, granted_by)
SELECT p.usr_uid, 'procurement_notices', 1, 1, 1, 1, p.usr_uid
FROM emcore_user_permissions p
JOIN USERS u ON u.USR_UID = p.usr_uid AND u.USR_STATUS = 'ACTIVE'
WHERE p.module_key = 'authorization' AND p.can_update = 1;
