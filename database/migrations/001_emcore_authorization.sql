-- EMCORE per-user, per-module CRUD authorization.
-- Verify the seeded ProcessMaker administrator USR_UID before production use.

CREATE TABLE IF NOT EXISTS emcore_modules (
    module_key VARCHAR(64) NOT NULL,
    name_fa VARCHAR(100) NOT NULL,
    name_en VARCHAR(100) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (module_key),
    KEY idx_emcore_modules_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_user_permissions (
    usr_uid CHAR(32) NOT NULL,
    module_key VARCHAR(64) NOT NULL,
    can_create TINYINT(1) NOT NULL DEFAULT 0,
    can_read TINYINT(1) NOT NULL DEFAULT 0,
    can_update TINYINT(1) NOT NULL DEFAULT 0,
    can_delete TINYINT(1) NOT NULL DEFAULT 0,
    granted_by CHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (usr_uid, module_key),
    KEY idx_emcore_permissions_module (module_key),
    KEY idx_emcore_permissions_granted_by (granted_by),
    CONSTRAINT fk_emcore_permissions_module
        FOREIGN KEY (module_key) REFERENCES emcore_modules(module_key)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO emcore_modules (module_key, name_fa, name_en, sort_order) VALUES
    ('authorization', 'دسترسی کاربران', 'User authorization', 10),
    ('companies', 'شرکت‌ها', 'Companies', 20),
    ('persons', 'اشخاص', 'Persons', 30),
    ('company_persons', 'سمت‌های سازمانی', 'Company-person relations', 40),
    ('mines', 'معادن', 'Mines', 50),
    ('mine_technical_managers', 'مسئولین فنی معادن', 'Mine technical managers', 60),
    ('memberships', 'عضویت‌ها و گواهینامه‌ها', 'Memberships and certifications', 70),
    ('tokens', 'توکن‌ها', 'Tokens', 80),
    ('email_accounts', 'حساب‌های ایمیل', 'Email accounts', 90),
    ('internet_services', 'اینترنت و میزبانی', 'Internet services', 100),
    ('attachments', 'پیوست‌ها', 'Attachments', 110)
ON DUPLICATE KEY UPDATE
    name_fa = VALUES(name_fa),
    name_en = VALUES(name_en),
    sort_order = VALUES(sort_order);

-- Bootstrap the supplied built-in administrator only when that active user
-- exists. INSERT IGNORE makes reruns safe and never overwrites later choices.
INSERT IGNORE INTO emcore_user_permissions
    (usr_uid, module_key, can_create, can_read, can_update, can_delete, granted_by)
SELECT
    u.USR_UID, m.module_key, 1, 1, 1, 1, u.USR_UID
FROM USERS u
CROSS JOIN emcore_modules m
WHERE u.USR_UID = '00000000000000000000000000000001'
  AND u.USR_STATUS = 'ACTIVE';
