<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Admin;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile as SlimUploadedFile;
use VideoSystem\Admin\TwigFactory;
use VideoSystem\Database\Connection;
use VideoSystem\Player\EmbedSettingsLoader;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

final class EmbedSettingsControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('access_log', 'ad_impressions', 'embed_settings', 'videos');
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
        $this->truncateTables('access_log', 'ad_impressions', 'embed_settings', 'videos');
        parent::tearDown();
    }

    private function adminPost(string $uri, array $formData, array $uploadedFiles = []): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', $uri)
            ->withParsedBody($formData)
            ->withUploadedFiles($uploadedFiles);

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

    public function testGlobalSavePersistsLogoPosition(): void
    {
        $response = $this->adminPost('/admin/embed-settings', [
            '_csrf' => 'test-csrf',
            'logo_position' => 'bottom-left',
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch('SELECT logo_position FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $this->assertNotNull($row);
        $this->assertSame('bottom-left', $row['logo_position']);
    }

    public function testGlobalSavePersistsUploadedLogoAndUsesStableResolvedUrl(): void
    {
        $response = $this->adminPost('/admin/embed-settings', [
            '_csrf' => 'test-csrf',
            'logo_url' => 'https://cdn.example/fallback-logo.png',
        ], [
            'logo_upload' => $this->makeUploadedFile('logo-bytes', 'brand.png', 'image/png'),
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch('SELECT logo_url, logo_upload_b2_key, logo_upload_original_name FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $this->assertNotNull($row);
        $this->assertSame('https://cdn.example/fallback-logo.png', $row['logo_url']);
        $this->assertSame('branding/global/logo.png', $row['logo_upload_b2_key']);
        $this->assertSame('brand.png', $row['logo_upload_original_name']);
        $this->assertTrue($this->b2->hasKey('branding/global/logo.png'));

        $settings = (new EmbedSettingsLoader())->normalize($row);
        $this->assertSame('https://example.com/branding/logo/global', $settings['logo_url']);
    }

    public function testGlobalSaveRemoveUploadedLogoPreservesFallbackUrl(): void
    {
        $this->insertEmbedSettings([
            'video_id' => null,
            'logo_url' => 'https://cdn.example/fallback-logo.png',
            'logo_upload_b2_key' => 'branding/global/logo.svg',
            'logo_upload_original_name' => 'brand.svg',
        ]);
        $this->b2->seed('branding/global/logo.svg', '<svg></svg>');

        $response = $this->adminPost('/admin/embed-settings', [
            '_csrf' => 'test-csrf',
            'logo_url' => 'https://cdn.example/fallback-logo.png',
            'remove_uploaded_logo' => '1',
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch('SELECT logo_url, logo_upload_b2_key, logo_upload_original_name FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $this->assertSame('https://cdn.example/fallback-logo.png', $row['logo_url']);
        $this->assertNull($row['logo_upload_b2_key']);
        $this->assertNull($row['logo_upload_original_name']);
        $this->assertFalse($this->b2->hasKey('branding/global/logo.svg'));
    }

    public function testGlobalSaveRejectsInvalidUploadedLogoAndPreservesExistingState(): void
    {
        $this->insertEmbedSettings([
            'video_id' => null,
            'logo_url' => 'https://cdn.example/original-logo.png',
            'logo_upload_b2_key' => 'branding/global/logo.png',
            'logo_upload_original_name' => 'original.png',
        ]);
        $this->b2->seed('branding/global/logo.png', 'old-logo');

        $response = $this->adminPost('/admin/embed-settings', [
            '_csrf' => 'test-csrf',
            'logo_url' => 'https://cdn.example/original-logo.png',
        ], [
            'logo_upload' => $this->makeUploadedFile('not-image', 'brand.txt', 'text/plain'),
        ]);

        $this->assertStatus(302, $response);

        $row = Connection::fetch('SELECT logo_url, logo_upload_b2_key, logo_upload_original_name FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $this->assertSame('https://cdn.example/original-logo.png', $row['logo_url']);
        $this->assertSame('branding/global/logo.png', $row['logo_upload_b2_key']);
        $this->assertSame('original.png', $row['logo_upload_original_name']);
        $this->assertSame('old-logo', $this->b2->read('branding/global/logo.png'));
        $this->assertSame('error', $_SESSION['flash']['type']);
    }

    public function testGlobalLogoRouteRedirectsToUploadedAsset(): void
    {
        $this->insertEmbedSettings([
            'video_id' => null,
            'logo_upload_b2_key' => 'branding/global/logo.png',
            'logo_upload_original_name' => 'brand.png',
        ]);
        $this->b2->seed('branding/global/logo.png', 'logo-content');

        $response = $this->get('/branding/logo/global');

        $this->assertStatus(302, $response);
        $this->assertStringContainsString('branding/global/logo.png', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('ttl=900', $response->getHeaderLine('Location'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testGlobalLogoRouteReturns404WithoutUploadedLogo(): void
    {
        $this->insertEmbedSettings([
            'video_id' => null,
            'logo_url' => 'https://cdn.example/logo.png',
        ]);

        $response = $this->get('/branding/logo/global');

        $this->assertStatus(404, $response);
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

    public function testGetGlobalEmbedSettingsRendersSavedLogoPositionInPreview(): void
    {
        $this->insertEmbedSettings([
            'video_id' => null,
            'logo_position' => 'bottom-left',
        ]);

        $response = $this->get('/admin/embed-settings');
        $this->assertStatus(200, $response);

        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        $this->assertStringContainsString('id="preview-logo" class="preview-logo bottom-left"', $body);
        $this->assertStringContainsString('<option value="bottom-left" selected>Bottom Left</option>', $body);
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

    public function testAdAnalyticsDisplaysVideoViewMetricsAndBannerPlacements(): void
    {
        $video = $this->insertVideo();
        $this->seedAccessLog((int) $video['id'], 'watch_open', 2, ['surface' => 'watch']);
        $this->seedAccessLog((int) $video['id'], 'playback_start', 1, ['surface' => 'watch', 'source_kind' => 'hls']);
        $this->seedAccessLog((int) $video['id'], 'ad_view', 3, ['placement' => 'watch_top_banner']);
        $this->seedAccessLog((int) $video['id'], 'ad_click', 1, ['placement' => 'watch_top_banner']);

        $response = $this->get('/admin/ad-analytics');
        $this->assertStatus(200, $response);

        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Watch Views', $body);
        $this->assertStringContainsString('Playback Starts', $body);
        $this->assertStringContainsString('Watch Top Banner', $body);
        $this->assertStringContainsString('Tracked click-through actions', $body);
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

    /**
     * @param array<string, mixed> $details
     */
    private function seedAccessLog(int $videoId, string $action, int $count, array $details = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            Connection::execute(
                'INSERT INTO access_log (video_id, ip_address, action, details_json)
                 VALUES (:vid, :ip, :action, :details_json)',
                [
                    ':vid' => $videoId,
                    ':ip' => '127.0.0.1',
                    ':action' => $action,
                    ':details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]
            );
        }
    }

    private function makeUploadedFile(string $content, string $clientFilename, string $mediaType): SlimUploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'embed_logo_');
        if ($path === false) {
            throw new \RuntimeException('Could not create temporary logo fixture.');
        }
        file_put_contents($path, $content);

        return new SlimUploadedFile(
            $path,
            $clientFilename,
            $mediaType,
            filesize($path) ?: 0,
            UPLOAD_ERR_OK,
            false
        );
    }
}
