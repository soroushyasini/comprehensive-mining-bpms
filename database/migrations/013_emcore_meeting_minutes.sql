-- Prerequisites: migrations 001/002, existing emcore_companies and USERS.
-- Additive only: never recreate or reseed business companies. Re-runnable.
CREATE TABLE IF NOT EXISTS emcore_minutes_company_codes (
    company_id INT UNSIGNED NOT NULL,
    code VARCHAR(32) NOT NULL,
    locked_at DATETIME DEFAULT NULL,
    updated_by_usr_uid CHAR(32) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id), UNIQUE KEY uq_minutes_company_code (code),
    FOREIGN KEY (company_id) REFERENCES emcore_companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Match by national ID, or exact name when the national ID was not supplied.
-- HAVING excludes ambiguous matches. The deployment preflight reports misses.
INSERT IGNORE INTO emcore_minutes_company_codes (company_id, code)
SELECT MIN(c.id), seeds.code FROM emcore_companies c JOIN (
    SELECT '10103956371' national_id, NULL name_fa, 'EMIDCO' code
    UNION ALL SELECT '10103992021', NULL, 'KGA'
    UNION ALL SELECT '10380584031', NULL, 'TSS'
    UNION ALL SELECT '10380617797', NULL, 'MKM'
    UNION ALL SELECT '10380253266', NULL, 'TKK'
    UNION ALL SELECT '14010802153', NULL, 'MICA'
    UNION ALL SELECT '9009870', NULL, 'EMIDCO-METAL'
    UNION ALL SELECT NULL, 'حفار گستر نائیین', 'HGN'
) seeds ON (seeds.national_id IS NOT NULL AND c.national_id = seeds.national_id)
    OR (seeds.national_id IS NULL AND BINARY c.name_fa = BINARY seeds.name_fa)
WHERE c.deleted_at IS NULL GROUP BY seeds.code HAVING COUNT(*) = 1;

CREATE TABLE IF NOT EXISTS emcore_minutes_counters (
    company_id INT UNSIGNED NOT NULL, jalali_year SMALLINT UNSIGNED NOT NULL,
    next_sequence BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (company_id, jalali_year),
    FOREIGN KEY (company_id) REFERENCES emcore_companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_meeting_minutes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    company_name_snapshot VARCHAR(200) NOT NULL,
    company_code_snapshot VARCHAR(32) DEFAULT NULL,
    record_origin ENUM('managed','legacy') NOT NULL,
    meeting_number VARCHAR(100) NOT NULL,
    number_key VARCHAR(100) NOT NULL,
    numbering_year SMALLINT UNSIGNED DEFAULT NULL,
    sequence_number BIGINT UNSIGNED DEFAULT NULL,
    title VARCHAR(500) NOT NULL,
    meeting_date_fa VARCHAR(10) DEFAULT NULL, meeting_date_en DATE DEFAULT NULL,
    start_time TIME DEFAULT NULL, end_time TIME DEFAULT NULL,
    ends_next_day TINYINT(1) NOT NULL DEFAULT 0,
    agenda TEXT DEFAULT NULL, notes TEXT DEFAULT NULL,
    metadata_complete TINYINT(1) NOT NULL DEFAULT 0,
    create_request_id CHAR(32) NOT NULL, create_payload_hash CHAR(64) NOT NULL,
    created_by_usr_uid CHAR(32) NOT NULL, updated_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL, lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_minutes_number (company_id, number_key),
    UNIQUE KEY uq_minutes_create_request (created_by_usr_uid, create_request_id),
    UNIQUE KEY uq_minutes_sequence (company_id, numbering_year, sequence_number),
    KEY idx_minutes_date (meeting_date_en, deleted_at, id),
    KEY idx_minutes_company (company_id, deleted_at, meeting_date_en),
    KEY idx_minutes_quality (metadata_complete, record_origin, deleted_at),
    FOREIGN KEY (company_id) REFERENCES emcore_companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_minutes_participants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, meeting_id BIGINT UNSIGNED NOT NULL,
    person_key VARCHAR(66) NOT NULL, usr_uid CHAR(32) DEFAULT NULL,
    name_snapshot VARCHAR(200) NOT NULL, organization_snapshot VARCHAR(200) DEFAULT NULL,
    attendance ENUM('present','absent') NOT NULL,
    is_chair TINYINT(1) NOT NULL DEFAULT 0, is_secretary TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id), UNIQUE KEY uq_minutes_person (meeting_id, person_key),
    KEY idx_minutes_user (usr_uid, meeting_id), KEY idx_minutes_person_key (person_key, meeting_id),
    FOREIGN KEY (meeting_id) REFERENCES emcore_meeting_minutes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_minutes_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, meeting_id BIGINT UNSIGNED NOT NULL,
    file_role ENUM('scan','attachment') NOT NULL,
    original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(80) NOT NULL,
    storage_path VARCHAR(500) NOT NULL, extension VARCHAR(10) NOT NULL,
    mime_type VARCHAR(150) NOT NULL, file_size BIGINT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL,
    replaces_file_id BIGINT UNSIGNED DEFAULT NULL, replacement_reason VARCHAR(1000) DEFAULT NULL,
    superseded_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL,
    upload_request_id CHAR(32) NOT NULL, upload_payload_hash CHAR(64) NOT NULL,
    uploaded_by_usr_uid CHAR(32) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_minutes_upload_request (uploaded_by_usr_uid, upload_request_id),
    KEY idx_minutes_files (meeting_id, file_role, deleted_at, superseded_at),
    FOREIGN KEY (meeting_id) REFERENCES emcore_meeting_minutes(id),
    FOREIGN KEY (replaces_file_id) REFERENCES emcore_minutes_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_minutes_download_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, file_id BIGINT UNSIGNED NOT NULL,
    actor_usr_uid CHAR(32) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL, downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_minutes_download_file (file_id, downloaded_at),
    FOREIGN KEY (file_id) REFERENCES emcore_minutes_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO emcore_modules (module_key,name_fa,name_en,sort_order)
VALUES ('meeting_minutes','صورت جلسات','Meeting minutes',160)
ON DUPLICATE KEY UPDATE name_fa=VALUES(name_fa),name_en=VALUES(name_en),sort_order=VALUES(sort_order),is_active=1;
INSERT IGNORE INTO emcore_user_permissions
    (usr_uid,module_key,can_create,can_read,can_update,can_delete,granted_by)
SELECT p.usr_uid,'meeting_minutes',1,1,1,1,p.usr_uid FROM emcore_user_permissions p
JOIN USERS u ON u.USR_UID=p.usr_uid AND u.USR_STATUS='ACTIVE'
WHERE p.module_key='authorization' AND p.can_update=1;
