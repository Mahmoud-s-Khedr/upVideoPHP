ALTER TABLE embed_settings
    ADD COLUMN logo_upload_b2_key VARCHAR(1024) NULL AFTER logo_url,
    ADD COLUMN logo_upload_original_name VARCHAR(255) NULL AFTER logo_upload_b2_key;
