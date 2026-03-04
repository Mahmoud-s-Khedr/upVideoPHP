-- ---------------------------------------------------------------------------
-- 016_multipart_upload_state
--
-- Adds multipart-upload state fields to videos so direct browser uploads larger
-- than 5 GB can resume safely and be finalized server-side.
-- ---------------------------------------------------------------------------

ALTER TABLE videos
    ADD COLUMN original_upload_mode ENUM('single', 'multipart') NOT NULL DEFAULT 'single'
        AFTER size_bytes,
    ADD COLUMN multipart_upload_id VARCHAR(255) DEFAULT NULL
        AFTER original_upload_mode,
    ADD COLUMN multipart_parts_json LONGTEXT DEFAULT NULL
        AFTER multipart_upload_id;
