-- Extend drilling reports with measured crew hours and optimistic concurrency.
-- Legacy crew rows intentionally keep worked_hours NULL: unknown is not 12 hours.

DROP PROCEDURE IF EXISTS emcore_apply_drilling_analytics_schema;
DELIMITER $$
CREATE PROCEDURE emcore_apply_drilling_analytics_schema()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'emcore_drilling_report_crew'
          AND COLUMN_NAME = 'worked_hours'
    ) THEN
        ALTER TABLE emcore_drilling_report_crew
            ADD COLUMN worked_hours DECIMAL(5,2) NULL AFTER worker_type;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'emcore_drilling_reports'
          AND COLUMN_NAME = 'lock_version'
    ) THEN
        ALTER TABLE emcore_drilling_reports
            ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'emcore_drilling_report_crew'
          AND CONSTRAINT_NAME = 'chk_emcore_drilling_crew_worked_hours'
    ) THEN
        ALTER TABLE emcore_drilling_report_crew
            ADD CONSTRAINT chk_emcore_drilling_crew_worked_hours
            CHECK (worked_hours IS NULL OR (worked_hours > 0 AND worked_hours <= 12));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'emcore_drilling_reports'
          AND CONSTRAINT_NAME = 'chk_emcore_drilling_report_lock_version'
    ) THEN
        ALTER TABLE emcore_drilling_reports
            ADD CONSTRAINT chk_emcore_drilling_report_lock_version
            CHECK (lock_version >= 1);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'emcore_drilling_reports'
          AND INDEX_NAME = 'idx_emcore_drilling_reports_dashboard'
    ) THEN
        ALTER TABLE emcore_drilling_reports
            ADD KEY idx_emcore_drilling_reports_dashboard
                (report_date_en, deleted_at, borehole_id, rig_id, shift);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'emcore_drilling_report_crew'
          AND INDEX_NAME = 'idx_emcore_drilling_crew_hours'
    ) THEN
        ALTER TABLE emcore_drilling_report_crew
            ADD KEY idx_emcore_drilling_crew_hours (report_id, worked_hours, role_key);
    END IF;
END$$
DELIMITER ;

CALL emcore_apply_drilling_analytics_schema();
DROP PROCEDURE emcore_apply_drilling_analytics_schema;
