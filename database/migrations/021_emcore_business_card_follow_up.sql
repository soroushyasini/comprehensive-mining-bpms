-- Track the relationship independently of the card's data review status.
-- Existing cards remain unclassified; re-running does not alter their values.
SET @emcore_bc_follow_up_sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE emcore_business_cards ADD COLUMN follow_up_status ENUM(''not_started'',''contacted'',''in_progress'',''positive_outcome'',''closed_no_result'') NULL DEFAULT NULL, ADD KEY ix_bc_follow_up (follow_up_status, deleted_at, id)',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_business_cards' AND COLUMN_NAME = 'follow_up_status'
);
PREPARE emcore_bc_follow_up_stmt FROM @emcore_bc_follow_up_sql;
EXECUTE emcore_bc_follow_up_stmt;
DEALLOCATE PREPARE emcore_bc_follow_up_stmt;
