-- ---------------------------------------------------------------------------
-- 015_encoding_job_heartbeat
--
-- Adds a heartbeat_at column to encoding_jobs so the stale-job reaper can
-- distinguish truly-stuck jobs from long-running ones (e.g. 2-hour 1080p
-- encodes). The worker updates this column every time it writes progress or
-- advances a stage. The reaper uses COALESCE(heartbeat_at, claimed_at) so
-- jobs claimed before this migration was applied are still reaped correctly.
-- ---------------------------------------------------------------------------

ALTER TABLE encoding_jobs
    ADD COLUMN heartbeat_at DATETIME DEFAULT NULL
        AFTER claimed_at;
