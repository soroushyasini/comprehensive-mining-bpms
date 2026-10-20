-- Add an independent tracking stage without relabeling historical outcomes.
-- Re-runnable on MySQL 8; old rows remain NULL until explicitly classified.
SET @emcore_stage_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE emcore_procurement_notices ADD COLUMN follow_up_stage ENUM(''registered'',''analysis'',''document_preparation'',''awaiting_envelope_opening'',''awaiting_price_meeting'') NULL DEFAULT NULL',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_procurement_notices' AND COLUMN_NAME = 'follow_up_stage'
);
PREPARE emcore_stage_stmt FROM @emcore_stage_sql;
EXECUTE emcore_stage_stmt;
DEALLOCATE PREPARE emcore_stage_stmt;
SET @emcore_stage_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE emcore_procurement_notices ADD KEY idx_procurement_follow_up_stage (follow_up_stage, deleted_at)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_procurement_notices' AND INDEX_NAME = 'idx_procurement_follow_up_stage'
);
PREPARE emcore_stage_stmt FROM @emcore_stage_sql;
EXECUTE emcore_stage_stmt;
DEALLOCATE PREPARE emcore_stage_stmt;
