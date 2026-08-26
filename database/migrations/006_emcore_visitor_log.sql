-- Arrival-only visitor register for reception and security operations.

CREATE TABLE IF NOT EXISTS emcore_visits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    visitor_name VARCHAR(200) NOT NULL,
    organization_name VARCHAR(200) DEFAULT NULL,
    purpose VARCHAR(1000) NOT NULL,
    host_usr_uid CHAR(32) DEFAULT NULL,
    host_name_snapshot VARCHAR(200) NOT NULL,
    visit_date_fa VARCHAR(10) NOT NULL,
    visit_date_en DATE NOT NULL,
    entered_at DATETIME NOT NULL,
    exited_at DATETIME DEFAULT NULL,
    notes VARCHAR(5000) DEFAULT NULL,
    created_by_usr_uid CHAR(32) NOT NULL,
    updated_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_emcore_visits_date_status (visit_date_en, exited_at, deleted_at),
    KEY idx_emcore_visits_host (host_usr_uid, visit_date_en, deleted_at),
    KEY idx_emcore_visits_visitor (visitor_name, visit_date_en),
    KEY idx_emcore_visits_created_by (created_by_usr_uid, created_at),
    CONSTRAINT chk_emcore_visits_exit_after_entry
        CHECK (exited_at IS NULL OR exited_at >= entered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO emcore_modules (module_key, name_fa, name_en, sort_order)
VALUES ('visitor_log', 'ثبت ورود و خروج مراجعان', 'Visitor log', 130)
ON DUPLICATE KEY UPDATE
    name_fa = VALUES(name_fa),
    name_en = VALUES(name_en),
    sort_order = VALUES(sort_order),
    is_active = 1;

-- Existing authorization administrators receive full initial module access.
INSERT IGNORE INTO emcore_user_permissions
    (usr_uid, module_key, can_create, can_read, can_update, can_delete, granted_by)
SELECT p.usr_uid, 'visitor_log', 1, 1, 1, 1, p.usr_uid
FROM emcore_user_permissions p
JOIN USERS u ON u.USR_UID = p.usr_uid AND u.USR_STATUS = 'ACTIVE'
WHERE p.module_key = 'authorization' AND p.can_update = 1;