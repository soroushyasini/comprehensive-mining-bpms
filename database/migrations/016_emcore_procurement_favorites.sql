-- Personal bookmarks are independent of business decisions and record versions.
CREATE TABLE IF NOT EXISTS emcore_procurement_favorites (
    usr_uid CHAR(32) NOT NULL,
    procurement_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usr_uid, procurement_id),
    KEY idx_procurement_favorite_notice (procurement_id),
    CONSTRAINT fk_procurement_favorite_notice FOREIGN KEY (procurement_id)
        REFERENCES emcore_procurement_notices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
