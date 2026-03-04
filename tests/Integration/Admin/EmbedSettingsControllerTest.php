<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Admin;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use VideoSystem\Admin\TwigFactory;
use VideoSystem\Database\Connection;
use VideoSystem\Player\EmbedSettingsLoader;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

final class EmbedSettingsControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('embed_settings', 'videos');
        TwigFactory::reset();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [
            'admin_id' => 1,
            'admin_username' => 'admin',
            'csrf_token' => 'test-csrf',
        ];
    }

    protected function tearDown(): void
    {
        TwigFactory::reset();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->truncateTables('embed_settings', 'videos');
        parent::tearDown();
    }

    private function adminPost(string $uri, array $formData): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', $uri)
            ->withParsedBody($formData);

        return $this->app->handle($request);
    }

    public function testGlobalSavePersistsExpandedAdsFields(): void
    {
        $response = $this->adminPost('/admin/embed-settings', [
            '_csrf' => 'test-csrf',
            'accent_color' => '#123456',
            'title_visible' => '1',
            'force_disable_adblock' => '1',
            'preroll_source_kind' => 'vast',
            'preroll_url' => 'https://ads.example/pre.xml',
            'preroll_skip_after' => '7',
            'postroll_source_kind' => 'mp4',
            'postroll_url' => 'https://ads.example/post.mp4',
            'watch_top_banner_html' => '<div>top</div>',
            'general_script_url' => 'https://ads.example/script.js',
            'general_html_code' => '<div>general</div>',
            'direct_play_url' => 'https://ads.example/direct',
            'direct_play_mode' => 'iframe',
            'direct_popup_bypass_iframe' => '1',
            'midroll_cues' => json_encode([
                [
                    'trigger_kind' => 'percent',
                    'trigger_value' => 50,
                    'source_kind' => 'vast',
                    'url' => 'https://ads.example/mid.xml',
                    'skip_after' => 5,
                    'click_url' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch('SELECT * FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $this->assertNotNull($row);
        $this->assertSame('vast', $row['preroll_source_kind']);
        $this->assertSame('iframe', $row['direct_play_mode']);
        $this->assertSame('<div>top</div>', $row['watch_top_banner_html']);
        $this->assertSame('<div>general</div>', $row['general_html_code']);
    }

    public function testVideoSavePersistsOnlyVideoAdsFields(): void
    {
        $video = $this->insertVideo();
        $this->insertEmbedSettings([
            'video_id' => null,
            'watch_top_banner_html' => '<div>global-banner</div>',
            'general_script_url' => 'https://ads.example/script.js',
            'direct_play_url' => 'https://ads.example/direct',
        ]);

        $response = $this->adminPost('/admin/videos/' . $video['uuid'] . '/embed', [
            '_csrf' => 'test-csrf',
            'force_disable_adblock' => '1',
            'preroll_source_kind' => 'vast',
            'preroll_url' => 'https://ads.example/pre.xml',
            'preroll_skip_after' => '6',
            'postroll_source_kind' => 'mp4',
            'postroll_url' => 'https://ads.example/post.mp4',
            'midroll_cues' => json_encode([
                [
                    'trigger_kind' => 'percent',
                    'trigger_value' => 150,
                    'source_kind' => 'vast',
                    'url' => 'https://ads.example/bad.xml',
                    'skip_after' => 5,
                ],
                [
                    'time_sec' => 20,
                    'url' => 'https://ads.example/legacy.mp4',
                    'skip_after' => 3,
                    'click_url' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch(
            'SELECT * FROM embed_settings WHERE video_id = :vid',
            [':vid' => $video['id']]
        );

        $this->assertNotNull($row);
        $this->assertSame('vast', $row['preroll_source_kind']);
        $this->assertNull($row['watch_top_banner_html']);
        $this->assertNull($row['direct_play_url']);

        $settings = (new EmbedSettingsLoader())->loadForVideo((int) $video['id']);
        $this->assertSame('<div>global-banner</div>', $settings['watch_top_banner_html']);
        $this->assertSame('https://ads.example/direct', $settings['direct_play_url']);
        $this->assertCount(1, $settings['midroll_cues']);
        $this->assertSame('seconds', $settings['midroll_cues'][0]['trigger_kind']);
        $this->assertSame(20, $settings['midroll_cues'][0]['trigger_value']);
    }

    // =========================================================================
    // I-19: GET /admin/embed-settings returns 200
    // =========================================================================

    public function testGetGlobalEmbedSettingsReturns200(): void
    {
        $response = $this->get('/admin/embed-settings');
        $this->assertStatus(200, $response);
        $this->assertHtmlResponse($response);
    }

    // =========================================================================
    // I-20 – I-23: Global save validation edge cases
    // =========================================================================

    public function testGlobalSaveWithMp4SourceKindAndValidUrlSavesToDb(): void
    {
        $response = $this->adminPost('/admin/embed-settings', [
            '_csrf'               => 'test-csrf',
            'preroll_source_kind' => 'mp4',
            'preroll_url'         => 'https://cdn.example/preroll.mp4',
            'preroll_skip_after'  => '5',
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch('SELECT preroll_source_kind, preroll_url FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $this->assertNotNull($row);
        $this->assertSame('mp4', $row['preroll_source_kind']);
        $this->assertSame('https://cdn.example/preroll.mp4', $row['preroll_url']);
    }

    public function testGlobalSaveWithSourceKindNoneClearsUrl(): void
    {
        // Seed a row that already has a URL
        $this->insertEmbedSettings([
            'video_id'            => null,
            'preroll_source_kind' => 'mp4',
            'preroll_url'         => 'https://cdn.example/old.mp4',
        ]);

        $response = $this->adminPost('/admin/embed-settings', [
            '_csrf'               => 'test-csrf',
            'preroll_source_kind' => 'none',
            'preroll_url'         => '',
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch('SELECT preroll_source_kind, preroll_url FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $this->assertSame('none', $row['preroll_source_kind']);
        $this->assertNull($row['preroll_url']);
    }

    public function testGlobalSaveWithInvalidUrlNullsPrerollUrl(): void
    {
        $response = $this->adminPost('/admin/embed-settings', [
            '_csrf'               => 'test-csrf',
            'preroll_source_kind' => 'mp4',
            'preroll_url'         => 'not-a-valid-url',
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch('SELECT preroll_url FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $this->assertNull($row['preroll_url']);
    }

    public function testGlobalSaveSkipAfterAbove30IsClamped(): void
    {
        $response = $this->adminPost('/admin/embed-settings', [
            '_csrf'              => 'test-csrf',
            'preroll_skip_after' => '35',
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch('SELECT preroll_skip_after FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $this->assertSame(30, (int) $row['preroll_skip_after']);
    }

    // =========================================================================
    // I-28 / I-32: GET /admin/videos/{uuid}/embed returns 200
    // =========================================================================

    public function testGetVideoEmbedFormReturns200(): void
    {
        $video = $this->insertVideo();
        $this->insertEmbedSettings(['video_id' => null]);

        $response = $this->get('/admin/videos/' . $video['uuid'] . '/embed');
        $this->assertStatus(200, $response);
        $this->assertHtmlResponse($response);
    }

    public function testGetVideoEmbedFormWithOverrideReturns200(): void
    {
        $video = $this->insertVideo();
        $this->insertEmbedSettings(['video_id' => null]);
        $this->insertEmbedSettings([
            'video_id'            => $video['id'],
            'preroll_source_kind' => 'mp4',
            'preroll_url'         => 'https://cdn.example/override.mp4',
        ]);

        $response = $this->get('/admin/videos/' . $video['uuid'] . '/embed');
        $this->assertStatus(200, $response);
    }

    // =========================================================================
    // I-31: delete-override removes per-video row
    // =========================================================================

    public function testDeleteOverrideRemovesPerVideoRow(): void
    {
        $video = $this->insertVideo();
        $this->insertEmbedSettings(['video_id' => null]);
        $this->insertEmbedSettings([
            'video_id'            => $video['id'],
            'preroll_source_kind' => 'mp4',
            'preroll_url'         => 'https://cdn.example/override.mp4',
        ]);

        $response = $this->adminPost('/admin/videos/' . $video['uuid'] . '/embed/delete-override', [
            '_csrf' => 'test-csrf',
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch(
            'SELECT id FROM embed_settings WHERE video_id = :vid',
            [':vid' => $video['id']]
        );
        $this->assertNull($row, 'Per-video override row should be deleted');
    }

    // =========================================================================
    // I-33 – I-36: Ad analytics
    // =========================================================================

    public function testAdAnalyticsReturns200WithNoData(): void
    {
        $response = $this->get('/admin/ad-analytics');
        $this->assertStatus(200, $response);
        $this->assertHtmlResponse($response);
    }

    public function testAdAnalyticsReturns200WithSeededImpressions(): void
    {
        $video = $this->insertVideo();
        $this->seedImpressions((int) $video['id'], 'preroll', 'start', 3);
        $this->seedImpressions((int) $video['id'], 'preroll', 'complete', 2);

        $response = $this->get('/admin/ad-analytics');
        $this->assertStatus(200, $response);

        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        $this->assertStringContainsString($video['original_name'], $body);
    }

    public function testAdAnalyticsDisplaysMidrollImpressions(): void
    {
        $video = $this->insertVideo();
        $this->seedImpressions((int) $video['id'], 'midroll', 'start', 1);

        $response = $this->get('/admin/ad-analytics');
        $this->assertStatus(200, $response);

        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        $this->assertStringContainsString('midroll', $body);
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function seedImpressions(int $videoId, string $position, string $event, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Connection::execute(
                'INSERT INTO ad_impressions (video_id, position, event) VALUES (:vid, :pos, :evt)',
                [':vid' => $videoId, ':pos' => $position, ':evt' => $event]
            );
        }
    }
}
