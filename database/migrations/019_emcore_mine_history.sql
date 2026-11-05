-- Requires 001/002 and the existing emcore_mines (id INT UNSIGNED).
-- Additive and re-runnable. Does not grant permissions or modify existing mines.
CREATE TABLE IF NOT EXISTS emcore_mine_history_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    title_key VARCHAR(200) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by_usr_uid CHAR(32) DEFAULT NULL,
    updated_by_usr_uid CHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mine_history_category_title (title_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO emcore_mine_history_categories (title,title_key,sort_order) VALUES
('پیگیری گمانه‌ها','پیگیریگمانهها',10),
('پیگیری آنالیزهای ارسالی','پیگیریآنالیزهایارسالی',20),
('پیگیری لاگ‌های ارسالی','پیگیریلاگهایارسالی',30);

CREATE TABLE IF NOT EXISTS emcore_mine_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mine_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    executed_on_fa VARCHAR(10) NOT NULL,
    executed_on_en DATE NOT NULL,
    created_by_usr_uid CHAR(32) NOT NULL,
    updated_by_usr_uid CHAR(32) NOT NULL,
    create_request_id CHAR(32) NOT NULL,
    create_payload_hash CHAR(64) NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    edited_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_mine_history_create (created_by_usr_uid,create_request_id),
    KEY idx_mine_history_list (mine_id,category_id,deleted_at,executed_on_en,created_at,id),
    FOREIGN KEY (mine_id) REFERENCES emcore_mines(id),
    FOREIGN KEY (category_id) REFERENCES emcore_mine_history_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_mine_history_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    history_id BIGINT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(80) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    extension VARCHAR(10) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    upload_request_id CHAR(32) NOT NULL,
    upload_payload_hash CHAR(64) NOT NULL,
    uploaded_by_usr_uid CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_mine_history_upload (uploaded_by_usr_uid,upload_request_id),
    KEY idx_mine_history_files (history_id,deleted_at,id),
    FOREIGN KEY (history_id) REFERENCES emcore_mine_history(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_mine_history_download_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    file_id BIGINT UNSIGNED NOT NULL,
    actor_usr_uid CHAR(32) NOT NULL,
    downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mine_history_download (file_id,downloaded_at),
    FOREIGN KEY (file_id) REFERENCES emcore_mine_history_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
