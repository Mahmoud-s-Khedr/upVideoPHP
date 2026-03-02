-- Expand the public ads system to support VAST, page banners, general ad code,
-- and first-play direct ads while keeping legacy MP4 video ads compatible.

ALTER TABLE embed_settings
    ADD COLUMN force_disable_adblock    TINYINT(1) NOT NULL DEFAULT 0 AFTER title_visible,
    ADD COLUMN preroll_source_kind      ENUM('none','mp4','vast') NOT NULL DEFAULT 'none' AFTER preroll_click_url,
    ADD COLUMN postroll_source_kind     ENUM('none','mp4','vast') NOT NULL DEFAULT 'none' AFTER postroll_click_url,
    ADD COLUMN watch_top_banner_html    MEDIUMTEXT NULL AFTER midroll_cues,
    ADD COLUMN watch_bottom_banner_html MEDIUMTEXT NULL AFTER watch_top_banner_html,
    ADD COLUMN embed_banner_html        MEDIUMTEXT NULL AFTER watch_bottom_banner_html,
    ADD COLUMN general_script_url       VARCHAR(2048) NULL AFTER embed_banner_html,
    ADD COLUMN general_html_code        MEDIUMTEXT NULL AFTER general_script_url,
    ADD COLUMN direct_play_url          VARCHAR(2048) NULL AFTER general_html_code,
    ADD COLUMN direct_play_mode         ENUM('popup','redirect','iframe') NOT NULL DEFAULT 'popup' AFTER direct_play_url,
    ADD COLUMN direct_popup_bypass_iframe TINYINT(1) NOT NULL DEFAULT 1 AFTER direct_play_mode,
    ADD COLUMN direct_download_url      VARCHAR(2048) NULL AFTER direct_popup_bypass_iframe,
    ADD COLUMN direct_download_mode     ENUM('popup','redirect','iframe') NOT NULL DEFAULT 'popup' AFTER direct_download_url;

UPDATE embed_settings
SET preroll_source_kind = CASE
        WHEN preroll_url IS NULL OR preroll_url = '' THEN 'none'
        ELSE 'mp4'
    END,
    postroll_source_kind = CASE
        WHEN postroll_url IS NULL OR postroll_url = '' THEN 'none'
        ELSE 'mp4'
    END;
