ALTER TABLE encoding_jobs
    ADD COLUMN current_stage VARCHAR(32) NOT NULL DEFAULT 'queued' AFTER current_rendition;
