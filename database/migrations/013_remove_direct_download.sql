-- Migration 013: Remove direct_download ad columns
-- There is no download surface in the system. These columns were stubbed but
-- never triggered by the player. Remove them to avoid confusion.

ALTER TABLE embed_settings
    DROP COLUMN direct_download_url,
    DROP COLUMN direct_download_mode;
