-- Migration 003: audio_tracks table
--
-- Stores pre-extracted audio-only HLS playlists produced during the fast
-- pre-processing phase (Step 5 of the encoding pipeline).
--
-- Each row represents one audio stream from the source video, demuxed to its
-- own HLS playlist via stream copy (no re-encoding) and uploaded to B2 before
-- the original file is uploaded — enabling full multi-track playback even if
-- the full HLS encoding pipeline never completes.
--
-- The b2_key_prefix column holds the B2 path prefix, e.g.
--   videos/{uuid}/audio_0/
-- Under which the worker uploads:
--   seg00000.ts, seg00001.ts, ... index.m3u8

CREATE TABLE audio_tracks (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    video_id        BIGINT UNSIGNED NOT NULL,
    track_index     TINYINT UNSIGNED NOT NULL,
    language_code   CHAR(8)         NOT NULL DEFAULT 'und',
    label           VARCHAR(64)     NOT NULL DEFAULT 'Unknown',
    b2_key_prefix   VARCHAR(1024)   NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    CONSTRAINT fk_audio_tracks_video
        FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE,

    UNIQUE KEY uq_audio_track (video_id, track_index),
    KEY idx_audio_video (video_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
