-- Manual assignments have no import batch. Existing imported assignments keep theirs.
SET @emcore_bc_manual_collections_sql = (
    SELECT IF(COUNT(*) = 0,
        'SELECT 1',
        'ALTER TABLE emcore_business_card_collections MODIFY COLUMN batch_id BIGINT UNSIGNED NULL DEFAULT NULL')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_business_card_collections'
        AND COLUMN_NAME = 'batch_id' AND IS_NULLABLE = 'NO'
);
PREPARE emcore_bc_manual_collections_stmt FROM @emcore_bc_manual_collections_sql;
EXECUTE emcore_bc_manual_collections_stmt;
DEALLOCATE PREPARE emcore_bc_manual_collections_stmt;
