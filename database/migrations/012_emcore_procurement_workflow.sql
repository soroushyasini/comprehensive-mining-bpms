-- Run once after 011. User IDs are assigned by the deployment initializer,
-- never guessed from a display name and never substituted for audit authors.
ALTER TABLE emcore_procurement_notices
    ADD COLUMN owner_usr_uid CHAR(32) DEFAULT NULL,
    ADD COLUMN manager_usr_uid CHAR(32) DEFAULT NULL,
    ADD COLUMN workflow_history_only TINYINT(1) NOT NULL DEFAULT 1,
    MODIFY participation_status ENUM('registered','interested','documents_submitted','won','lost','unknown','not_interested','withdrawn') NOT NULL DEFAULT 'registered',
    ADD KEY idx_procurement_owner (owner_usr_uid, deleted_at),
    ADD KEY idx_procurement_manager (manager_usr_uid, deleted_at);

CREATE TABLE IF NOT EXISTS emcore_procurement_workflows (
    procurement_id BIGINT UNSIGNED NOT NULL,
    app_uid CHAR(32) NOT NULL,
    workflow_stage ENUM('activation','follow_up','result_review','completed','stopped') NOT NULL,
    sync_state ENUM('ready','pending','error') NOT NULL DEFAULT 'ready',
    pending_result ENUM('won','lost') DEFAULT NULL,
    last_del_index INT UNSIGNED NOT NULL DEFAULT 1,
    last_sync_error VARCHAR(1000) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (procurement_id),
    UNIQUE KEY uq_procurement_case (app_uid),
    CONSTRAINT fk_procurement_workflow_notice FOREIGN KEY (procurement_id) REFERENCES emcore_procurement_notices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_procurement_commands (
    request_id CHAR(32) NOT NULL,
    procurement_id BIGINT UNSIGNED NOT NULL,
    actor_usr_uid CHAR(32) NOT NULL,
    app_uid CHAR(32) DEFAULT NULL,
    source_del_index INT UNSIGNED DEFAULT NULL,
    command_type VARCHAR(32) NOT NULL,
    expected_version INT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    command_state ENUM('prepared','pending','completed','abandoned','error') NOT NULL DEFAULT 'prepared',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (request_id),
    KEY idx_procurement_commands_pending (command_state, procurement_id),
    CONSTRAINT fk_procurement_command_notice FOREIGN KEY (procurement_id) REFERENCES emcore_procurement_notices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_procurement_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    procurement_id BIGINT UNSIGNED NOT NULL,
    request_id CHAR(32) NOT NULL,
    actor_usr_uid CHAR(32) NOT NULL,
    actor_role VARCHAR(20) NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    body TEXT NOT NULL,
    corrects_event_id BIGINT UNSIGNED DEFAULT NULL,
    before_data JSON DEFAULT NULL,
    after_data JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_procurement_event_request (request_id),
    KEY idx_procurement_events_notice (procurement_id, id),
    CONSTRAINT fk_procurement_event_notice FOREIGN KEY (procurement_id) REFERENCES emcore_procurement_notices(id),
    CONSTRAINT fk_procurement_event_correction FOREIGN KEY (corrects_event_id) REFERENCES emcore_procurement_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_procurement_event_reads (
    event_id BIGINT UNSIGNED NOT NULL,
    usr_uid CHAR(32) NOT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, usr_uid),
    CONSTRAINT fk_procurement_read_event FOREIGN KEY (event_id) REFERENCES emcore_procurement_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emcore_procurement_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    recipient_usr_uid CHAR(32) NOT NULL,
    read_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_procurement_notification (event_id, recipient_usr_uid),
    KEY idx_procurement_notification_inbox (recipient_usr_uid, read_at, id),
    CONSTRAINT fk_procurement_notification_event FOREIGN KEY (event_id) REFERENCES emcore_procurement_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE emcore_procurement_files
    MODIFY file_role ENUM('notice_document','final_submission','activity_attachment') NOT NULL,
    ADD COLUMN event_id BIGINT UNSIGNED DEFAULT NULL,
    ADD KEY idx_procurement_file_event (event_id),
    ADD CONSTRAINT fk_procurement_file_event FOREIGN KEY (event_id) REFERENCES emcore_procurement_events(id),
    ADD CONSTRAINT chk_procurement_event_file CHECK ((file_role = 'activity_attachment' AND event_id IS NOT NULL) OR (file_role <> 'activity_attachment' AND event_id IS NULL));
