-- EMCORE mine relationship model and reviewed legacy drilling mine mapping.
-- Safe to rerun on MySQL 8.0. The north Tappeh Siah merge preserves ID 8,
-- moves any future references from ID 9, and soft-retires the duplicate.

DROP PROCEDURE IF EXISTS emcore_apply_mine_relationship_schema;
DELIMITER $$
CREATE PROCEDURE emcore_apply_mine_relationship_schema()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_mines'
          AND COLUMN_NAME = 'company_id' AND IS_NULLABLE = 'NO'
    ) THEN
        ALTER TABLE emcore_mines MODIFY company_id INT UNSIGNED NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_mines'
          AND COLUMN_NAME = 'relationship_type'
    ) THEN
        ALTER TABLE emcore_mines
            ADD COLUMN relationship_type VARCHAR(32) NOT NULL DEFAULT 'owned' AFTER company_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_mines'
          AND COLUMN_NAME = 'related_person_id'
    ) THEN
        ALTER TABLE emcore_mines
            ADD COLUMN related_person_id INT UNSIGNED NULL AFTER relationship_type;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_mines'
          AND COLUMN_NAME = 'merged_into_id'
    ) THEN
        ALTER TABLE emcore_mines
            ADD COLUMN merged_into_id INT UNSIGNED NULL AFTER related_person_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_mines'
          AND CONSTRAINT_NAME = 'fk_emcore_mines_related_person'
    ) THEN
        ALTER TABLE emcore_mines
            ADD CONSTRAINT fk_emcore_mines_related_person
                FOREIGN KEY (related_person_id) REFERENCES emcore_persons(id)
                ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_mines'
          AND CONSTRAINT_NAME = 'fk_emcore_mines_merged_into'
    ) THEN
        ALTER TABLE emcore_mines
            ADD CONSTRAINT fk_emcore_mines_merged_into
                FOREIGN KEY (merged_into_id) REFERENCES emcore_mines(id)
                ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_mines'
          AND CONSTRAINT_NAME = 'chk_emcore_mines_relationship_type'
    ) THEN
        ALTER TABLE emcore_mines
            ADD CONSTRAINT chk_emcore_mines_relationship_type
                CHECK (relationship_type IN ('owned', 'contractor', 'personnel_related'));
    END IF;

    -- MySQL 8.0 rejects a cross-column CHECK on related_person_id because that
    -- column uses a cascading foreign key. The mines API enforces the related
    -- company/person invariant on every create and update.
END$$
DELIMITER ;
CALL emcore_apply_mine_relationship_schema();
DROP PROCEDURE emcore_apply_mine_relationship_schema;

DROP PROCEDURE IF EXISTS emcore_apply_reviewed_mine_mapping;
DELIMITER $$
CREATE PROCEDURE emcore_apply_reviewed_mine_mapping()
BEGIN
    DECLARE north_canonical_exists INT DEFAULT 0;
    DECLARE north_duplicate_exists INT DEFAULT 0;
    DECLARE conflict_count INT DEFAULT 0;

    SELECT COUNT(*) INTO north_canonical_exists
    FROM emcore_mines
    WHERE id = 8 AND mine_name = 'تپه سیاه شمالی (سولفیدی)';

    SELECT COUNT(*) INTO north_duplicate_exists
    FROM emcore_mines
    WHERE id = 9 AND mine_name = 'تپه سیاه شمالی (اکسیدی)';

    IF north_canonical_exists = 0
       AND NOT EXISTS (SELECT 1 FROM emcore_mines WHERE id = 8 AND mine_name = 'تپه سیاه شمالی') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mine ID 8 does not match the reviewed north Tappeh Siah record';
    END IF;

    IF north_duplicate_exists = 0
       AND NOT EXISTS (SELECT 1 FROM emcore_mines WHERE id = 9 AND merged_into_id = 8) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mine ID 9 does not match the reviewed duplicate north Tappeh Siah record';
    END IF;

    SELECT COUNT(*) INTO conflict_count
    FROM emcore_boreholes source
    JOIN emcore_boreholes target
      ON target.mine_id = 8
     AND target.borehole_code = source.borehole_code
     AND target.deleted_at IS NULL
    WHERE source.mine_id = 9 AND source.deleted_at IS NULL;

    IF conflict_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Duplicate borehole codes block the north Tappeh Siah merge';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM emcore_mines WHERE id = 3 AND mine_name = 'تنگل نورا'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mine ID 3 does not match the reviewed Tangele Nora record';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM emcore_companies
        WHERE deleted_at IS NULL AND name_fa = 'معدن کاران مس میامی'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reviewed Miyami company is missing';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM emcore_persons
        WHERE id = 9 AND deleted_at IS NULL AND last_name = 'فیض‌آبادی'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reviewed Feyzabadi person record is missing';
    END IF;

    START TRANSACTION;

    UPDATE emcore_boreholes SET mine_id = 8, updated_at = NOW() WHERE mine_id = 9;
    UPDATE emcore_mine_technical_managers SET mine_id = 8, updated_at = NOW() WHERE mine_id = 9;
    UPDATE emcore_attachments SET entity_id = 8 WHERE entity_type = 'mine' AND entity_id = 9;

    UPDATE emcore_mines
    SET mine_name = 'تپه سیاه شمالی',
        alias_name = 'تپه سیاه',
        mineral_type = 'مس',
        ore_subtype = 'سولفیدی، اکسیدی',
        relationship_type = 'owned',
        related_person_id = NULL,
        updated_at = NOW()
    WHERE id = 8;

    UPDATE emcore_mines
    SET merged_into_id = 8,
        deleted_at = COALESCE(deleted_at, NOW()),
        notes = CASE
            WHEN LOCATE('ادغام در معدن شماره 8', COALESCE(notes, '')) > 0 THEN notes
            ELSE CONCAT_WS('\n', NULLIF(notes, ''), 'ادغام در معدن شماره 8: تپه سیاه شمالی')
        END,
        updated_at = NOW()
    WHERE id = 9;

    UPDATE emcore_mines
    SET alias_name = 'تنگل', relationship_type = 'owned', updated_at = NOW()
    WHERE id = 3 AND mine_name = 'تنگل نورا';

    INSERT INTO emcore_companies
        (name_fa, legal_type, registration_number, national_id, phone, is_active, created_at, updated_at)
    SELECT 'حفار گستر نائیین', 'other', NULL, NULL, NULL, 1, NOW(), NOW()
    WHERE NOT EXISTS (
        SELECT 1 FROM emcore_companies
        WHERE deleted_at IS NULL AND name_fa = 'حفار گستر نائیین'
    );

    INSERT INTO emcore_mines
        (company_id, relationship_type, related_person_id, mine_name, mineral_type,
         status, alias_name, notes, created_at, updated_at)
    SELECT c.id, 'contractor', NULL, 'راه چمن', 'نامشخص', 'فاقد اطلاعات', NULL,
           'پروژه تاریخی حفاری پیمانکاری؛ کارفرما/طرف مرتبط: حفار گستر نائیین', NOW(), NOW()
    FROM emcore_companies c
    WHERE c.deleted_at IS NULL AND c.name_fa = 'حفار گستر نائیین'
      AND NOT EXISTS (
          SELECT 1 FROM emcore_mines WHERE deleted_at IS NULL AND mine_name = 'راه چمن'
      )
    ORDER BY c.id LIMIT 1;

    INSERT INTO emcore_mines
        (company_id, relationship_type, related_person_id, mine_name, mineral_type,
         status, alias_name, notes, created_at, updated_at)
    SELECT c.id, 'owned', NULL, 'میامی', 'مس', 'فاقد اطلاعات', NULL,
           'پروژه تاریخی حفاری؛ شرکت مرتبط: معدن کاران مس میامی', NOW(), NOW()
    FROM emcore_companies c
    WHERE c.deleted_at IS NULL AND c.name_fa = 'معدن کاران مس میامی'
      AND NOT EXISTS (
          SELECT 1 FROM emcore_mines WHERE deleted_at IS NULL AND mine_name = 'میامی'
      )
    ORDER BY c.id LIMIT 1;

    INSERT INTO emcore_mines
        (company_id, relationship_type, related_person_id, mine_name, mineral_type,
         status, alias_name, notes, created_at, updated_at)
    SELECT NULL, 'personnel_related', p.id, 'کلاته برق', 'نامشخص', 'فاقد اطلاعات', NULL,
           'پروژه تاریخی حفاری؛ مرتبط با سید محمدداود فیض‌آبادی', NOW(), NOW()
    FROM emcore_persons p
    WHERE p.deleted_at IS NULL AND p.id = 9
      AND NOT EXISTS (
          SELECT 1 FROM emcore_mines WHERE deleted_at IS NULL AND mine_name = 'کلاته برق'
      )
    LIMIT 1;

    COMMIT;
END$$
DELIMITER ;
CALL emcore_apply_reviewed_mine_mapping();
DROP PROCEDURE emcore_apply_reviewed_mine_mapping;

SET @emcore_migration_actor = (
    SELECT p.usr_uid
    FROM emcore_user_permissions p
    JOIN USERS u ON u.USR_UID = p.usr_uid AND u.USR_STATUS = 'ACTIVE'
    WHERE p.module_key = 'authorization' AND p.can_update = 1
    ORDER BY p.updated_at DESC
    LIMIT 1
);

INSERT INTO emcore_audit_log
    (request_id, actor_usr_uid, module_key, action, entity_type, entity_id,
     after_data, metadata, created_at)
SELECT MD5(UUID()), @emcore_migration_actor, 'mines', 'migration_merge', 'mine', '8:9',
       JSON_OBJECT('canonical_mine_id', 8, 'retired_mine_id', 9,
                   'mine_name', 'تپه سیاه شمالی', 'ore_subtype', 'سولفیدی، اکسیدی'),
       JSON_OBJECT('migration', '005_emcore_mine_relationships_and_legacy_mapping'), NOW()
WHERE @emcore_migration_actor IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM emcore_audit_log
      WHERE module_key = 'mines' AND action = 'migration_merge'
        AND entity_type = 'mine' AND entity_id = '8:9'
  );
