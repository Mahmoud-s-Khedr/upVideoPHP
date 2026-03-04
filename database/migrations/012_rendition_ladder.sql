-- Migration 012: Rendition Ladder
-- Stores the per-tier encoding parameters that RenditionPipeline uses.
-- Seed rows match the previous hardcoded RENDITION_LADDER constant so
-- existing deployments work immediately after running this migration.

CREATE TABLE IF NOT EXISTS rendition_ladder (
    id         INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    label      VARCHAR(10)      NOT NULL,
    position   TINYINT UNSIGNED NOT NULL DEFAULT 0    COMMENT 'Encode order: lower = earlier in the queue',
    width      SMALLINT UNSIGNED NOT NULL,
    height     SMALLINT UNSIGNED NOT NULL,
    crf        TINYINT UNSIGNED NOT NULL               COMMENT 'FFmpeg -crf value (lower = higher quality)',
    vbitrate   VARCHAR(10)      NOT NULL               COMMENT 'FFmpeg video bitrate, e.g. 3000k',
    abitrate   VARCHAR(10)      NOT NULL               COMMENT 'FFmpeg audio bitrate, e.g. 192k',
    updated_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO rendition_ladder (label, position, width, height, crf, vbitrate, abitrate) VALUES
    ('1080p', 0, 1920, 1080, 25, '3000k', '192k'),
    ('720p',  1, 1280,  720, 26, '2200k', '128k'),
    ('540p',  2,  960,  540, 27, '1500k', '128k'),
    ('480p',  3,  854,  480, 28, '1000k', '128k'),
    ('360p',  4,  640,  360, 29,  '500k',  '96k')
ON DUPLICATE KEY UPDATE
    position = VALUES(position),
    width    = VALUES(width),
    height   = VALUES(height),
    crf      = VALUES(crf),
    vbitrate = VALUES(vbitrate),
    abitrate = VALUES(abitrate);
