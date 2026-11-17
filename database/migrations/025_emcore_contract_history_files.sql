-- Additive archive-first release. No permission grants or legacy-data rewrites.
-- Legacy history.document_id remains a logical-document link: its historical
-- version is unknown and must never be guessed from today's current version.
CREATE TABLE IF NOT EXISTS emcore_contract_history_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    history_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    created_by CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    deleted_at DATETIME DEFAULT NULL,
    deleted_by CHAR(32) DEFAULT NULL,
    delete_reason VARCHAR(500) DEFAULT NULL,
    UNIQUE KEY history_version (history_id,version_id),
    KEY version_usage (version_id,deleted_at),
    FOREIGN KEY (history_id) REFERENCES emcore_contract_history(id),
    FOREIGN KEY (version_id) REFERENCES emcore_contract_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
