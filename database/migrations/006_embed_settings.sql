-- Embed settings for the video player (global and per-video overrides).
-- Global default: video_id IS NULL.
-- Per-video override: video_id = videos.id.

CREATE TABLE IF NOT EXISTS embed_settings (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id        BIGINT UNSIGNED NULL,
    logo_url        VARCHAR(1024) NULL,
    logo_position   ENUM('top-left','top-right','bottom-left','bottom-right') DEFAULT 'top-right',
    accent_color    VARCHAR(7) DEFAULT '#FF0000',
    title_visible   TINYINT(1) DEFAULT 1,
    preroll_url     VARCHAR(2048) NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_embed_video (video_id),
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert global default row
INSERT INTO embed_settings (video_id, accent_color, title_visible) VALUES (NULL, '#FF0000', 1);
