-- Append-only EMCORE change history.

CREATE TABLE IF NOT EXISTS emcore_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id CHAR(32) NOT NULL,
    actor_usr_uid CHAR(32) NOT NULL,
    module_key VARCHAR(64) NOT NULL,
    action VARCHAR(20) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(64) NOT NULL,
    before_data LONGTEXT DEFAULT NULL,
    after_data LONGTEXT DEFAULT NULL,
    metadata LONGTEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emcore_audit_created (created_at),
    KEY idx_emcore_audit_actor (actor_usr_uid, created_at),
    KEY idx_emcore_audit_entity (entity_type, entity_id, created_at),
    KEY idx_emcore_audit_module (module_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO emcore_modules (module_key, name_fa, name_en, sort_order)
VALUES ('audit_log', 'تاریخچه تغییرات', 'Audit log', 15)
ON DUPLICATE KEY UPDATE
    name_fa = VALUES(name_fa),
    name_en = VALUES(name_en),
    sort_order = VALUES(sort_order),
    is_active = 1;

-- Existing authorization administrators receive read-only audit access.
INSERT IGNORE INTO emcore_user_permissions
    (usr_uid, module_key, can_create, can_read, can_update, can_delete, granted_by)
SELECT p.usr_uid, 'audit_log', 0, 1, 0, 0, p.usr_uid
FROM emcore_user_permissions p
JOIN USERS u ON u.USR_UID = p.usr_uid AND u.USR_STATUS = 'ACTIVE'
WHERE p.module_key = 'authorization' AND p.can_update = 1;
