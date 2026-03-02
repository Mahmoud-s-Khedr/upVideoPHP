-- PHP Video Upload & HLS Streaming System — Initial Schema
-- Requires: MySQL 8.0+ or MariaDB 10.6+
--   (SELECT ... FOR UPDATE SKIP LOCKED requires these minimum versions)
-- Charset: utf8mb4 / utf8mb4_unicode_ci on all tables

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ---------------------------------------------------------------------------
-- videos
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS videos (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36)         NOT NULL,
    original_name       VARCHAR(512)     NOT NULL,   -- display only; never used in filesystem paths
    duration_sec        INT UNSIGNED     DEFAULT NULL,
    size_bytes          BIGINT UNSIGNED  NOT NULL,

    original_b2_key     VARCHAR(1024)    DEFAULT NULL,  -- set after B2 upload; NULL while still local
    original_deleted_at DATETIME         DEFAULT NULL,  -- set once original is deleted from B2
    poster_b2_key       VARCHAR(1024)    DEFAULT NULL,
    sprite_b2_key       VARCHAR(1024)    DEFAULT NULL,
    sprite_columns      TINYINT UNSIGNED DEFAULT NULL,  -- tile grid width (for seek preview)
    sprite_rows         SMALLINT UNSIGNED DEFAULT NULL, -- tile grid height
    sprite_frame_count  SMALLINT UNSIGNED DEFAULT NULL, -- total frames in sprite

    status              ENUM(
                            'pending',
                            'queued',
                            'processing',
                            'uploading',
                            'ready',
                            'error'
                        ) NOT NULL DEFAULT 'pending',
    error_message       TEXT             DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_uuid      (uuid),
    INDEX      idx_status   (status),
    INDEX      idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- encoding_jobs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS encoding_jobs (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id          BIGINT UNSIGNED NOT NULL,

    status            ENUM('queued','claimed','done','failed') NOT NULL DEFAULT 'queued',
    attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 3,
    worker_pid        INT              DEFAULT NULL,
    claimed_at        DATETIME         DEFAULT NULL,
    retry_after       DATETIME         DEFAULT NULL,
    last_error        TEXT             DEFAULT NULL,
    progress_pct      TINYINT UNSIGNED DEFAULT 0,
    current_rendition VARCHAR(16)      DEFAULT NULL,
    cancel_requested  TINYINT(1)       NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY fk_ej_video (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    INDEX idx_status_attempts  (status, attempts),
    INDEX idx_video_status     (video_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- renditions
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS renditions (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id      BIGINT UNSIGNED NOT NULL,
    label         VARCHAR(16)     NOT NULL,
    width         SMALLINT UNSIGNED NOT NULL,
    height        SMALLINT UNSIGNED NOT NULL,
    bitrate_kbps  SMALLINT UNSIGNED NOT NULL,
    b2_key_prefix VARCHAR(1024)   NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY fk_rend_video (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- encryption_keys
-- Key material (key_hex, iv_hex) is stored AES-256 encrypted via app layer.
-- The plaintext hex values are NEVER written to this table.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS encryption_keys (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id      BIGINT UNSIGNED NOT NULL,
    key_index     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    key_hex       VARCHAR(512)    NOT NULL,   -- AES-256-encrypted ciphertext (base64)
    iv_hex        VARCHAR(512)    NOT NULL,   -- AES-256-encrypted ciphertext (base64)
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_video_key_index (video_id, key_index),
    FOREIGN KEY fk_ek_video (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- subtitles
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subtitles (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id      BIGINT UNSIGNED NOT NULL,
    track_index   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    language_code CHAR(8)         NOT NULL,
    label         VARCHAR(64)     NOT NULL,
    is_forced     TINYINT(1)      NOT NULL DEFAULT 0,
    b2_vtt_key    VARCHAR(1024)   NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_video_track (video_id, track_index),
    INDEX idx_video_lang (video_id, language_code),
    FOREIGN KEY fk_sub_video (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- api_keys
-- Tokens are stored as bcrypt hashes (password_hash / password_verify).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(128)    NOT NULL,
    key_hash      VARCHAR(255)    NOT NULL,
    can_upload    TINYINT(1)      NOT NULL DEFAULT 1,
    can_stream    TINYINT(1)      NOT NULL DEFAULT 1,
    revoked_at    DATETIME        DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- access_log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS access_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id      BIGINT UNSIGNED NOT NULL,
    ip_address    VARCHAR(45)     NOT NULL,
    key_index     SMALLINT UNSIGNED DEFAULT NULL,
    action        ENUM('key_request','playlist','segment','original') NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY fk_al_video (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    INDEX idx_video_created (video_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
