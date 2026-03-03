-- Migration 011: widen track label columns safely
--
-- Defensive backfill: legacy/manual data may contain NULL values even though
-- earlier schema definitions were NOT NULL.
UPDATE audio_tracks
SET label = 'Unknown'
WHERE label IS NULL;

UPDATE subtitles
SET label = 'Unknown'
WHERE label IS NULL;

ALTER TABLE audio_tracks
    MODIFY COLUMN label VARCHAR(512) NOT NULL DEFAULT 'Unknown';

ALTER TABLE subtitles
    MODIFY COLUMN label VARCHAR(512) NOT NULL DEFAULT 'Unknown';

-- ---------------------------------------------------------------------------
-- Manual rollback (if this migration must be reverted)
-- ---------------------------------------------------------------------------
-- ALTER TABLE audio_tracks
--     MODIFY COLUMN label VARCHAR(64) NOT NULL DEFAULT 'Unknown';
--
-- ALTER TABLE subtitles
--     MODIFY COLUMN label VARCHAR(64) NOT NULL;
