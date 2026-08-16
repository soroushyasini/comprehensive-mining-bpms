-- Lossless staging for legacy daily drilling report imports.
-- Rows with incomplete or unmapped business keys remain here for correction;
-- successfully normalized rows link to their canonical EMCORE report.

CREATE TABLE IF NOT EXISTS emcore_drilling_legacy_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id CHAR(32) NOT NULL,
    source_legacy_id BIGINT UNSIGNED NOT NULL,
    source_data LONGTEXT NOT NULL,
    normalized_mine_name VARCHAR(255) DEFAULT NULL,
    normalized_borehole_code VARCHAR(100) DEFAULT NULL,
    import_status VARCHAR(24) NOT NULL DEFAULT 'pending',
    validation_messages LONGTEXT DEFAULT NULL,
    report_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcore_drilling_legacy_source_id (source_legacy_id),
    KEY idx_emcore_drilling_legacy_batch_status (batch_id, import_status),
    KEY idx_emcore_drilling_legacy_report (report_id),
    CONSTRAINT fk_emcore_drilling_legacy_report
        FOREIGN KEY (report_id) REFERENCES emcore_drilling_reports(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
