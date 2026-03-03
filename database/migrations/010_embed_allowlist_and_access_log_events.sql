ALTER TABLE embed_settings
    ADD COLUMN allowed_embed_origins JSON NULL AFTER direct_download_mode;

ALTER TABLE access_log
    MODIFY COLUMN action VARCHAR(64) NOT NULL,
    ADD COLUMN session_id VARCHAR(64) NULL AFTER ip_address,
    ADD COLUMN details_json JSON NULL AFTER action;

ALTER TABLE access_log
    ADD INDEX idx_access_log_action_created (action, created_at),
    ADD INDEX idx_access_log_session_created (session_id, created_at);
