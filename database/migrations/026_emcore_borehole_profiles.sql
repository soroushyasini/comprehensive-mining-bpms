-- Extend the canonical borehole registry without changing existing IDs or report links.
-- Apply after 003 and 009 before publishing the borehole panel and updated APIs.

DROP PROCEDURE IF EXISTS emcore_apply_borehole_profile_schema;
DELIMITER $$
CREATE PROCEDURE emcore_apply_borehole_profile_schema()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_boreholes'
          AND COLUMN_NAME = 'lock_version'
    ) THEN
        ALTER TABLE emcore_boreholes
            ADD COLUMN area_zone VARCHAR(150) NULL,
            ADD COLUMN collar_x DECIMAL(15,4) NULL,
            ADD COLUMN collar_y DECIMAL(15,4) NULL,
            ADD COLUMN collar_z DECIMAL(15,4) NULL,
            ADD COLUMN coordinate_system VARCHAR(100) NULL,
            ADD COLUMN azimuth_deg DECIMAL(6,3) NULL,
            ADD COLUMN dip_deg DECIMAL(6,3) NULL,
            ADD COLUMN profile_depth_m DECIMAL(12,3) NULL,
            ADD COLUMN profile_start_date_fa VARCHAR(10) NULL,
            ADD COLUMN profile_completion_date_fa VARCHAR(10) NULL,
            ADD COLUMN profile_drilling_status VARCHAR(24) NULL,
            ADD COLUMN mine_locked TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 1;
    END IF;
END$$
DELIMITER ;
CALL emcore_apply_borehole_profile_schema();
DROP PROCEDURE emcore_apply_borehole_profile_schema;

-- Keep the mine locked even if a report is later soft-deleted or reassigned.
-- The triggers also cover report imports that write directly to the table.
DROP TRIGGER IF EXISTS emcore_borehole_lock_on_report_insert;
DROP TRIGGER IF EXISTS emcore_borehole_lock_on_report_update;
DELIMITER $$
CREATE TRIGGER emcore_borehole_lock_on_report_insert
AFTER INSERT ON emcore_drilling_reports FOR EACH ROW
BEGIN
    UPDATE emcore_boreholes SET mine_locked = 1, updated_at = updated_at
    WHERE id = NEW.borehole_id AND mine_locked = 0;
END$$

CREATE TRIGGER emcore_borehole_lock_on_report_update
AFTER UPDATE ON emcore_drilling_reports FOR EACH ROW
BEGIN
    IF OLD.borehole_id <> NEW.borehole_id THEN
        UPDATE emcore_boreholes SET mine_locked = 1, updated_at = updated_at
        WHERE id = NEW.borehole_id AND mine_locked = 0;
    END IF;
END$$
DELIMITER ;

UPDATE emcore_boreholes b
SET b.mine_locked = 1, b.updated_at = b.updated_at
WHERE b.mine_locked = 0 AND EXISTS (
    SELECT 1 FROM emcore_drilling_reports r WHERE r.borehole_id = b.id
);

INSERT INTO emcore_modules (module_key, name_fa, name_en, sort_order)
VALUES ('boreholes', 'گمانه‌ها', 'Boreholes', 119)
ON DUPLICATE KEY UPDATE module_key = module_key;
