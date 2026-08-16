-- EMCORE drilling operations foundation.
-- Legacy daily reports are imported without deduplication. The contextual
-- combination (borehole, report date, shift) is indexed but intentionally
-- not unique because the source contains distinct reports for the same shift.

CREATE TABLE IF NOT EXISTS emcore_boreholes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mine_id INT UNSIGNED NOT NULL,
    borehole_code VARCHAR(100) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcore_boreholes_mine_code (mine_id, borehole_code),
    KEY idx_emcore_boreholes_mine_active (mine_id, deleted_at, borehole_code),
    CONSTRAINT fk_emcore_boreholes_mine
        FOREIGN KEY (mine_id) REFERENCES emcore_mines(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_drilling_rigs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    serial_number VARCHAR(50) NOT NULL,
    display_name VARCHAR(100) DEFAULT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcore_drilling_rigs_serial (serial_number),
    KEY idx_emcore_drilling_rigs_active (deleted_at, status, serial_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_drilling_checklist_items (
    item_key VARCHAR(32) NOT NULL,
    item_order SMALLINT UNSIGNED NOT NULL,
    label_fa VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_key),
    UNIQUE KEY uq_emcore_drilling_checklist_order (item_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_drilling_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    legacy_id BIGINT UNSIGNED DEFAULT NULL,
    report_number VARCHAR(64) DEFAULT NULL,
    legacy_form_serial VARCHAR(100) DEFAULT NULL,
    borehole_id INT UNSIGNED NOT NULL,
    rig_id INT UNSIGNED NOT NULL,
    report_date_fa VARCHAR(10) NOT NULL,
    report_date_en DATE NOT NULL,
    shift VARCHAR(10) NOT NULL,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    rig_hours DECIMAL(12,2) DEFAULT NULL,
    drill_start_depth DECIMAL(12,2) NOT NULL DEFAULT 0,
    drill_end_depth DECIMAL(12,2) NOT NULL DEFAULT 0,
    drill_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    corebox_start INT DEFAULT NULL,
    corebox_end INT DEFAULT NULL,
    water_amount DECIMAL(14,3) NOT NULL DEFAULT 0,
    diesel_amount DECIMAL(14,3) NOT NULL DEFAULT 0,
    oil_amount DECIMAL(14,3) NOT NULL DEFAULT 0,
    supermix_amount DECIMAL(14,3) NOT NULL DEFAULT 0,
    bentonite_amount DECIMAL(14,3) NOT NULL DEFAULT 0,
    soda_amount DECIMAL(14,3) NOT NULL DEFAULT 0,
    cement_amount DECIMAL(14,3) NOT NULL DEFAULT 0,
    lv_pack VARCHAR(100) DEFAULT NULL,
    operation_state VARCHAR(24) NOT NULL DEFAULT 'drilling',
    stop_causes VARCHAR(255) DEFAULT NULL,
    stop_duration_hours DECIMAL(5,2) DEFAULT NULL,
    incoming_equipment TEXT DEFAULT NULL,
    outgoing_equipment TEXT DEFAULT NULL,
    checklist_notes TEXT DEFAULT NULL,
    operation_description TEXT DEFAULT NULL,
    issues_suggestions TEXT DEFAULT NULL,
    legacy_inserted_at DATETIME DEFAULT NULL,
    legacy_source_data LONGTEXT DEFAULT NULL,
    created_by_usr_uid CHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcore_drilling_reports_legacy_id (legacy_id),
    UNIQUE KEY uq_emcore_drilling_reports_number (report_number),
    KEY idx_emcore_drilling_reports_context (borehole_id, report_date_en, shift, deleted_at),
    KEY idx_emcore_drilling_reports_rig_date (rig_id, report_date_en, deleted_at),
    KEY idx_emcore_drilling_reports_created_by (created_by_usr_uid, created_at),
    CONSTRAINT fk_emcore_drilling_reports_borehole
        FOREIGN KEY (borehole_id) REFERENCES emcore_boreholes(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_emcore_drilling_reports_rig
        FOREIGN KEY (rig_id) REFERENCES emcore_drilling_rigs(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_drilling_report_crew (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    role_key VARCHAR(32) NOT NULL,
    person_id INT UNSIGNED DEFAULT NULL,
    worker_name_snapshot VARCHAR(255) NOT NULL,
    worker_type VARCHAR(16) NOT NULL DEFAULT 'temporary',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emcore_drilling_crew_report (report_id, role_key, sort_order),
    KEY idx_emcore_drilling_crew_person (person_id),
    CONSTRAINT fk_emcore_drilling_crew_report
        FOREIGN KEY (report_id) REFERENCES emcore_drilling_reports(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_emcore_drilling_crew_person
        FOREIGN KEY (person_id) REFERENCES emcore_persons(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_drilling_report_checklist (
    report_id BIGINT UNSIGNED NOT NULL,
    item_key VARCHAR(32) NOT NULL,
    is_checked TINYINT(1) NOT NULL DEFAULT 0,
    note VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (report_id, item_key),
    KEY idx_emcore_drilling_checklist_item (item_key, is_checked),
    CONSTRAINT fk_emcore_drilling_checklist_report
        FOREIGN KEY (report_id) REFERENCES emcore_drilling_reports(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_emcore_drilling_checklist_item
        FOREIGN KEY (item_key) REFERENCES emcore_drilling_checklist_items(item_key)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO emcore_modules (module_key, name_fa, name_en, sort_order)
VALUES ('drilling_daily_reports', 'گزارش روزانه حفاری', 'Daily drilling reports', 120)
ON DUPLICATE KEY UPDATE
    name_fa = VALUES(name_fa),
    name_en = VALUES(name_en),
    sort_order = VALUES(sort_order),
    is_active = 1;

INSERT INTO emcore_drilling_rigs (serial_number, display_name, status) VALUES
    ('1030', 'دستگاه حفاری 1030', 'active'),
    ('1031', 'دستگاه حفاری 1031', 'active'),
    ('1036', 'دستگاه حفاری 1036', 'active')
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name);

INSERT INTO emcore_drilling_checklist_items (item_key, item_order, label_fa) VALUES
    ('site_safe', 1, 'سایت حفاری ایمن و مرتب است'),
    ('rods_supported', 2, 'رادها بر روی ساپورت قرار دارند'),
    ('electrical_checked', 3, 'کابل‌ها و اتصالات الکتریکی بررسی شدند'),
    ('hydraulic_checked', 4, 'شلنگ‌ها و اتصالات هیدرولیکی بررسی شدند'),
    ('spindle_oil_checked', 5, 'روغن اسپیندل بررسی شد'),
    ('hydraulic_oil_checked', 6, 'روغن هیدرولیک بررسی شد'),
    ('engine_oil_checked', 7, 'روغن موتور بررسی شد'),
    ('engine_water_checked', 8, 'آب موتور بررسی شد'),
    ('fuel_checked', 9, 'مقدار سوخت بررسی شد'),
    ('rig_level_checked', 10, 'تراز بودن دستگاه بررسی شد'),
    ('wireline_checked', 11, 'سیم بکسل وایرلاین بررسی شد'),
    ('spindle_greased', 12, 'گریس‌کاری اسپیندل انجام شد'),
    ('mud_pump_greased', 13, 'گریس‌کاری پمپ گل انجام شد'),
    ('wireline_bearing_greased', 14, 'گریس‌کاری یاتاقان وایرلاین انجام شد')
ON DUPLICATE KEY UPDATE
    item_order = VALUES(item_order),
    label_fa = VALUES(label_fa),
    is_active = 1;

-- Existing authorization administrators receive full initial module access.
INSERT IGNORE INTO emcore_user_permissions
    (usr_uid, module_key, can_create, can_read, can_update, can_delete, granted_by)
SELECT p.usr_uid, 'drilling_daily_reports', 1, 1, 1, 1, p.usr_uid
FROM emcore_user_permissions p
JOIN USERS u ON u.USR_UID = p.usr_uid AND u.USR_STATUS = 'ACTIVE'
WHERE p.module_key = 'authorization' AND p.can_update = 1;
