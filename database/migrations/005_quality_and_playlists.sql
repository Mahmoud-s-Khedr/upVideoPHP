-- Migration 005: Per-video quality selection + Playlist management
--
-- Run AFTER migrations 001–004.
-- This file is intended to run once under migration tracking.

-- ---------------------------------------------------------------------------
-- 1. Add source_height to videos (populated at upload time from ffprobe)
-- ---------------------------------------------------------------------------
ALTER TABLE videos
    ADD COLUMN source_height SMALLINT UNSIGNED DEFAULT NULL
        AFTER size_bytes;

-- ---------------------------------------------------------------------------
-- 2. Add target_qualities to videos (JSON array, e.g. ["720p","480p"])
--    NULL = not yet configured (status stays 'pending' until set)
-- ---------------------------------------------------------------------------
ALTER TABLE videos
    ADD COLUMN target_qualities JSON DEFAULT NULL
        AFTER source_height;

-- ---------------------------------------------------------------------------
-- 3. playlists — admin-managed, UUID-addressed playlist records
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playlists (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid        CHAR(36)     NOT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT         DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_playlists_uuid UNIQUE (uuid),
    INDEX idx_playlists_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. playlist_videos — many-to-many join with explicit position ordering
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS playlist_videos (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    playlist_id BIGINT UNSIGNED NOT NULL,
    video_id    BIGINT UNSIGNED NOT NULL,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    added_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT uq_playlist_video UNIQUE (playlist_id, video_id),
    INDEX idx_pv_playlist_pos (playlist_id, position),

    CONSTRAINT fk_pv_playlist FOREIGN KEY (playlist_id)
        REFERENCES playlists (id) ON DELETE CASCADE,
    CONSTRAINT fk_pv_video FOREIGN KEY (video_id)
        REFERENCES videos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
