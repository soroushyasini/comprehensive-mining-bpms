-- Add searchable exhibition/collector provenance without modifying legacy cards.
CREATE TABLE IF NOT EXISTS emcore_business_card_exhibitions (
    code VARCHAR(64) PRIMARY KEY,
    title VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_business_card_collectors (
    code VARCHAR(64) PRIMARY KEY,
    display_name VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_business_card_collections (
    card_id BIGINT UNSIGNED NOT NULL,
    exhibition_code VARCHAR(64) NOT NULL,
    collector_code VARCHAR(64) NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    created_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (card_id, exhibition_code, collector_code),
    KEY ix_bc_collection_filter (exhibition_code, collector_code, card_id),
    FOREIGN KEY (card_id) REFERENCES emcore_business_cards(id),
    FOREIGN KEY (exhibition_code) REFERENCES emcore_business_card_exhibitions(code),
    FOREIGN KEY (collector_code) REFERENCES emcore_business_card_collectors(code),
    FOREIGN KEY (batch_id) REFERENCES emcore_business_card_import_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
