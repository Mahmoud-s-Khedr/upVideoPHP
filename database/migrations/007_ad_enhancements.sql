-- Ad system enhancements: extended pre-roll, post-roll, mid-roll cue points, and impression analytics.

ALTER TABLE embed_settings
    ADD COLUMN preroll_skip_after  SMALLINT UNSIGNED NOT NULL DEFAULT 5
        COMMENT '0 = unskippable, 1-30 = seconds before skip button appears',
    ADD COLUMN preroll_click_url   VARCHAR(2048) NULL
        COMMENT 'Click-through landing page for pre-roll ad',
    ADD COLUMN postroll_url        VARCHAR(2048) NULL,
    ADD COLUMN postroll_skip_after SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    ADD COLUMN postroll_click_url  VARCHAR(2048) NULL,
    ADD COLUMN midroll_cues        JSON NULL
        COMMENT 'Array of {time_sec,url,skip_after,click_url} objects';

CREATE TABLE IF NOT EXISTS ad_impressions (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id    BIGINT UNSIGNED NOT NULL,
    position    ENUM('preroll','midroll','postroll') NOT NULL,
    event       ENUM('start','skip','complete','click') NOT NULL,
    cue_index   TINYINT UNSIGNED NULL          COMMENT 'Mid-roll cue index; NULL for pre/post-roll',
    session_id  VARCHAR(64) NULL               COMMENT 'Client-generated session ID (random hex)',
    ip_hash     CHAR(64) NULL                  COMMENT 'SHA-256 of remote IP (privacy-safe)',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_video_pos (video_id, position),
    INDEX idx_ai_created   (created_at),
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
