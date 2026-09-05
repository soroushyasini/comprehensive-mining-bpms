-- Add a separate historical-record path to the trade-document registry.
-- Existing managed records remain unchanged. Historical numbers may be empty
-- or repeated and never advance an issuer counter.

DROP PROCEDURE IF EXISTS emcore_apply_trade_legacy_schema;
DELIMITER $$
CREATE PROCEDURE emcore_apply_trade_legacy_schema()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_cases'
          AND COLUMN_NAME = 'record_origin'
    ) THEN
        ALTER TABLE emcore_trade_cases
            ADD COLUMN record_origin VARCHAR(16) NOT NULL DEFAULT 'managed' AFTER issuer_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_cases'
          AND COLUMN_NAME = 'numbering_issue'
    ) THEN
        ALTER TABLE emcore_trade_cases
            ADD COLUMN numbering_issue VARCHAR(24) NOT NULL DEFAULT 'none' AFTER pi_number;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_cases'
          AND COLUMN_NAME = 'numbering_note'
    ) THEN
        ALTER TABLE emcore_trade_cases
            ADD COLUMN numbering_note VARCHAR(1000) DEFAULT NULL AFTER numbering_issue;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_cases'
          AND INDEX_NAME = 'uq_emcore_trade_cases_number'
    ) THEN
        ALTER TABLE emcore_trade_cases DROP INDEX uq_emcore_trade_cases_number;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_cases'
          AND COLUMN_NAME IN ('sequence_number', 'number_year', 'pi_number')
          AND IS_NULLABLE = 'NO'
    ) THEN
        ALTER TABLE emcore_trade_cases
            MODIFY sequence_number INT UNSIGNED NULL,
            MODIFY number_year SMALLINT UNSIGNED NULL,
            MODIFY pi_number VARCHAR(64) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_cases'
          AND COLUMN_NAME = 'managed_pi_number'
    ) THEN
        ALTER TABLE emcore_trade_cases
            ADD COLUMN managed_pi_number VARCHAR(64)
                GENERATED ALWAYS AS (
                    CASE WHEN record_origin = 'managed' THEN pi_number ELSE NULL END
                ) STORED AFTER pi_number;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_cases'
          AND INDEX_NAME = 'uq_emcore_trade_cases_managed_pi'
    ) THEN
        ALTER TABLE emcore_trade_cases
            ADD UNIQUE KEY uq_emcore_trade_cases_managed_pi (managed_pi_number);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_cases'
          AND INDEX_NAME = 'idx_emcore_trade_cases_pi_number'
    ) THEN
        ALTER TABLE emcore_trade_cases
            ADD KEY idx_emcore_trade_cases_pi_number (pi_number);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_cases'
          AND INDEX_NAME = 'idx_emcore_trade_cases_origin'
    ) THEN
        ALTER TABLE emcore_trade_cases
            ADD KEY idx_emcore_trade_cases_origin (record_origin, deleted_at, updated_at);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_documents'
          AND COLUMN_NAME = 'record_origin'
    ) THEN
        ALTER TABLE emcore_trade_documents
            ADD COLUMN record_origin VARCHAR(16) NOT NULL DEFAULT 'managed' AFTER document_type;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_documents'
          AND INDEX_NAME = 'uq_emcore_trade_documents_number'
    ) THEN
        ALTER TABLE emcore_trade_documents DROP INDEX uq_emcore_trade_documents_number;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_documents'
          AND COLUMN_NAME = 'document_number' AND IS_NULLABLE = 'NO'
    ) THEN
        ALTER TABLE emcore_trade_documents
            MODIFY document_number VARCHAR(64) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_documents'
          AND COLUMN_NAME = 'managed_document_number'
    ) THEN
        ALTER TABLE emcore_trade_documents
            ADD COLUMN managed_document_number VARCHAR(64)
                GENERATED ALWAYS AS (
                    CASE WHEN record_origin = 'managed' THEN document_number ELSE NULL END
                ) STORED AFTER document_number;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_documents'
          AND INDEX_NAME = 'uq_emcore_trade_documents_managed_number'
    ) THEN
        ALTER TABLE emcore_trade_documents
            ADD UNIQUE KEY uq_emcore_trade_documents_managed_number (managed_document_number);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_documents'
          AND INDEX_NAME = 'idx_emcore_trade_documents_number'
    ) THEN
        ALTER TABLE emcore_trade_documents
            ADD KEY idx_emcore_trade_documents_number (document_number);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emcore_trade_document_versions'
          AND COLUMN_NAME = 'file_role'
    ) THEN
        ALTER TABLE emcore_trade_document_versions
            ADD COLUMN file_role VARCHAR(24) NOT NULL DEFAULT 'revision' AFTER version_state;
    END IF;
END$$
DELIMITER ;

CALL emcore_apply_trade_legacy_schema();
DROP PROCEDURE emcore_apply_trade_legacy_schema;
