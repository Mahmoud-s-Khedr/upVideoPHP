-- Migration 004: Add source column to subtitles table
--
-- Distinguishes subtitles automatically extracted by the encoding pipeline
-- from subtitles manually uploaded by an admin.

ALTER TABLE subtitles
    ADD COLUMN source ENUM('extracted', 'uploaded') NOT NULL DEFAULT 'extracted'
        AFTER is_forced;
