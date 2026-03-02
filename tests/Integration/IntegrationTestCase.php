<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration;

use PHPUnit\Framework\TestCase;
use VideoSystem\Database\Connection;

/**
 * Base class for integration tests that require a real MySQL database.
 *
 * Integration tests are automatically skipped when the database is unreachable,
 * so `composer test:unit` always passes without a DB.
 *
 * Requires these env vars (set in phpunit.xml for CI; override for local dev):
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static bool $dbAvailable = false;

    /**
     * Attempt a DB connection once per suite run.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        Connection::reset();
        try {
            self::$dbAvailable = Connection::ping();
        } catch (\Throwable) {
            self::$dbAvailable = false;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$dbAvailable) {
            $this->markTestIncomplete(
                'Database not reachable. Set DB_HOST / DB_NAME / DB_USER / DB_PASS to run integration tests.'
            );
        }

        // Reset the singleton so each test starts with a fresh connection state.
        Connection::reset();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Truncate one or more tables (order matters for FK constraints — disable checks first).
     */
    protected function truncateTables(string ...$tables): void
    {
        $pdo = Connection::get();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            // Validate: only allow identifier-safe names (letters, digits, underscores)
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                throw new \InvalidArgumentException("Invalid table name: {$table}");
            }
            $safe = '`' . str_replace('`', '', $table) . '`';
            $pdo->exec('TRUNCATE TABLE ' . $safe);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Insert a videos row and return the inserted data (including generated id).
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function insertVideo(array $override = []): array
    {
        $defaults = [
            'uuid'          => $this->newUuid(),
            'original_name' => 'test_video.mp4',
            'size_bytes'    => 1048576,
            'status'        => 'queued',
        ];
        $data = array_merge($defaults, $override);

        Connection::execute(
            'INSERT INTO videos (uuid, original_name, size_bytes, status)
             VALUES (:uuid, :original_name, :size_bytes, :status)',
            [
                ':uuid'          => $data['uuid'],
                ':original_name' => $data['original_name'],
                ':size_bytes'    => $data['size_bytes'],
                ':status'        => $data['status'],
            ]
        );

        $data['id'] = Connection::lastInsertId();
        return $data;
    }

    /**
     * Insert a playlists row and return the inserted data.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function insertPlaylist(array $override = []): array
    {
        $defaults = [
            'uuid'        => $this->newUuid(),
            'title'       => 'Test Playlist',
            'description' => 'Test playlist description',
        ];
        $data = array_merge($defaults, $override);

        Connection::execute(
            'INSERT INTO playlists (uuid, title, description)
             VALUES (:uuid, :title, :description)',
            [
                ':uuid'        => $data['uuid'],
                ':title'       => $data['title'],
                ':description' => $data['description'],
            ]
        );

        $data['id'] = Connection::lastInsertId();
        return $data;
    }

    protected function addPlaylistVideo(int $playlistId, int $videoId, int $position = 0): void
    {
        Connection::execute(
            'INSERT INTO playlist_videos (playlist_id, video_id, position)
             VALUES (:playlist_id, :video_id, :position)',
            [
                ':playlist_id' => $playlistId,
                ':video_id'    => $videoId,
                ':position'    => $position,
            ]
        );
    }

    /**
     * Insert an embed_settings row and return the inserted data.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function insertEmbedSettings(array $override = []): array
    {
        $defaults = [
            'video_id'            => null,
            'logo_url'            => null,
            'logo_position'       => 'top-right',
            'accent_color'        => '#FF0000',
            'title_visible'       => true,
            'force_disable_adblock' => false,
            'preroll_url'         => null,
            'preroll_skip_after'  => 5,
            'preroll_click_url'   => null,
            'preroll_source_kind' => 'none',
            'postroll_url'        => null,
            'postroll_skip_after' => 5,
            'postroll_click_url'  => null,
            'postroll_source_kind' => 'none',
            'midroll_cues'        => null,
            'watch_top_banner_html' => null,
            'watch_bottom_banner_html' => null,
            'embed_banner_html'     => null,
            'general_script_url'    => null,
            'general_html_code'     => null,
            'direct_play_url'       => null,
            'direct_play_mode'      => 'popup',
            'direct_popup_bypass_iframe' => true,
            'direct_download_url'   => null,
            'direct_download_mode'  => 'popup',
        ];
        $data = array_merge($defaults, $override);

        Connection::execute(
            'INSERT INTO embed_settings
             (video_id, logo_url, logo_position, accent_color, title_visible, force_disable_adblock, preroll_url,
              preroll_skip_after, preroll_click_url, preroll_source_kind,
              postroll_url, postroll_skip_after, postroll_click_url, postroll_source_kind, midroll_cues,
              watch_top_banner_html, watch_bottom_banner_html, embed_banner_html,
              general_script_url, general_html_code,
              direct_play_url, direct_play_mode, direct_popup_bypass_iframe,
              direct_download_url, direct_download_mode)
             VALUES
             (:video_id, :logo_url, :logo_position, :accent_color, :title_visible, :force_disable_adblock, :preroll_url,
              :preroll_skip_after, :preroll_click_url, :preroll_source_kind,
              :postroll_url, :postroll_skip_after, :postroll_click_url, :postroll_source_kind, :midroll_cues,
              :watch_top_banner_html, :watch_bottom_banner_html, :embed_banner_html,
              :general_script_url, :general_html_code,
              :direct_play_url, :direct_play_mode, :direct_popup_bypass_iframe,
              :direct_download_url, :direct_download_mode)',
            [
                ':video_id'            => $data['video_id'],
                ':logo_url'            => $data['logo_url'],
                ':logo_position'       => $data['logo_position'],
                ':accent_color'        => $data['accent_color'],
                ':title_visible'       => $data['title_visible'] ? 1 : 0,
                ':force_disable_adblock' => $data['force_disable_adblock'] ? 1 : 0,
                ':preroll_url'         => $data['preroll_url'],
                ':preroll_skip_after'  => $data['preroll_skip_after'],
                ':preroll_click_url'   => $data['preroll_click_url'],
                ':preroll_source_kind' => $data['preroll_source_kind'],
                ':postroll_url'        => $data['postroll_url'],
                ':postroll_skip_after' => $data['postroll_skip_after'],
                ':postroll_click_url'  => $data['postroll_click_url'],
                ':postroll_source_kind' => $data['postroll_source_kind'],
                ':midroll_cues'        => $data['midroll_cues'],
                ':watch_top_banner_html' => $data['watch_top_banner_html'],
                ':watch_bottom_banner_html' => $data['watch_bottom_banner_html'],
                ':embed_banner_html'   => $data['embed_banner_html'],
                ':general_script_url'  => $data['general_script_url'],
                ':general_html_code'   => $data['general_html_code'],
                ':direct_play_url'     => $data['direct_play_url'],
                ':direct_play_mode'    => $data['direct_play_mode'],
                ':direct_popup_bypass_iframe' => $data['direct_popup_bypass_iframe'] ? 1 : 0,
                ':direct_download_url' => $data['direct_download_url'],
                ':direct_download_mode' => $data['direct_download_mode'],
            ]
        );

        $data['id'] = Connection::lastInsertId();
        return $data;
    }

    /**
     * Insert an encoding_jobs row for the given video and return it.
     *
     * @return array<string, mixed>
     */
    protected function insertJob(int $videoId, string $status = 'queued'): array
    {
        Connection::execute(
            'INSERT INTO encoding_jobs (video_id, status) VALUES (:vid, :status)',
            [':vid' => $videoId, ':status' => $status]
        );
        $id = Connection::lastInsertId();
        return Connection::fetch('SELECT * FROM encoding_jobs WHERE id = :id', [':id' => $id]) ?? [];
    }

    /**
     * Insert an api_keys row with a bcrypt-hashed token.
     */
    protected function insertApiKey(
        string $name,
        string $plaintext,
        bool   $canUpload = true,
        bool   $canStream = true,
    ): void {
        Connection::execute(
            'INSERT INTO api_keys (name, key_hash, can_upload, can_stream)
             VALUES (:name, :hash, :upload, :stream)',
            [
                ':name'   => $name,
                ':hash'   => password_hash($plaintext, PASSWORD_BCRYPT),
                ':upload' => $canUpload ? 1 : 0,
                ':stream' => $canStream ? 1 : 0,
            ]
        );
    }

    /** Generate a v4-style UUID for test data. */
    protected function newUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
