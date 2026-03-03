<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Player;

use VideoSystem\Player\PlayerTwigFactory;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * Public watch page integration tests.
 *
 * GET /watch/{uuid}
 */
final class WatchControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('embed_settings', 'videos');
        PlayerTwigFactory::reset();
    }

    protected function tearDown(): void
    {
        PlayerTwigFactory::reset();
        $this->truncateTables('embed_settings', 'videos');
        parent::tearDown();
    }

    public function testReturns200HtmlForValidUuid(): void
    {
        $video    = $this->insertVideo(['status' => 'ready', 'original_name' => 'Watchable']);
        $response = $this->get("/watch/{$video['uuid']}");

        $this->assertStatus(200, $response);
        $this->assertHtmlResponse($response);

        $body = (string) $response->getBody();
        $this->assertStringContainsString('Watchable', $body);
        $this->assertStringContainsString("mode: 'watch'", $body);
        $this->assertStringContainsString($video['uuid'], $body);
        $this->assertStringContainsString('/watch/' . $video['uuid'] . '/bootstrap.json', $body);
    }

    public function testReturns404ForBadUuidFormat(): void
    {
        $response = $this->get('/watch/not-a-uuid');

        $this->assertStatus(404, $response);
    }

    public function testReturns404ForMissingVideo(): void
    {
        $response = $this->get('/watch/' . $this->newUuid());

        $this->assertStatus(404, $response);
    }

    public function testRenderedPayloadIncludesBootstrapJson(): void
    {
        $video    = $this->insertVideo(['status' => 'processing', 'original_name' => 'Bootstrap Test']);
        $response = $this->get("/watch/{$video['uuid']}");
        $body     = (string) $response->getBody();

        $this->assertStringContainsString('"playback_mode":"pending"', $body);
        $this->assertStringContainsString('"video_uuid":"' . $video['uuid'] . '"', $body);
        $this->assertStringContainsString('bootstrapUrl', $body);
    }

    public function testBootstrapJsonRouteReturnsPayload(): void
    {
        $video = $this->insertVideo(['status' => 'processing', 'original_name' => 'Bootstrap Route']);

        $response = $this->get("/watch/{$video['uuid']}/bootstrap.json");
        $data = $this->json($response);

        $this->assertStatus(200, $response);
        $this->assertSame($video['uuid'], $data['video_uuid']);
        $this->assertArrayHasKey('audio_tracks', $data);
        $this->assertArrayHasKey('subtitle_tracks', $data);
        $this->assertArrayHasKey('processing_hls_url', $data);
    }

    public function testRouteIsPublicWithoutAuth(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->get("/watch/{$video['uuid']}");

        $this->assertStatus(200, $response);
    }

    public function testUsesPerVideoEmbedSettingsBeforeGlobalFallback(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'video_id'      => null,
            'accent_color'  => '#00FF00',
            'title_visible' => false,
            'watch_top_banner_html' => '<div>global-banner</div>',
            'preroll_source_kind' => 'mp4',
            'preroll_url' => 'https://ads.example/global.mp4',
        ]);
        $this->insertEmbedSettings([
            'video_id' => (int) $video['id'],
            'force_disable_adblock' => true,
            'preroll_source_kind' => 'vast',
            'preroll_url' => 'https://ads.example/override.xml',
        ]);

        $response = $this->get("/watch/{$video['uuid']}");
        $body     = (string) $response->getBody();

        $this->assertStringContainsString('"accent_color":"#00FF00"', $body);
        $this->assertStringContainsString('"preroll_source_kind":"vast"', $body);
        $this->assertStringContainsString('"preroll_url":"https:\/\/ads.example\/override.xml"', $body);
        $this->assertStringContainsString('<div>global-banner</div>', $body);
    }

    public function testFallsBackToGlobalEmbedSettings(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'video_id'      => null,
            'accent_color'  => '#ABCDEF',
            'title_visible' => false,
        ]);

        $response = $this->get("/watch/{$video['uuid']}");
        $body     = (string) $response->getBody();

        $this->assertStringContainsString('"accent_color":"#ABCDEF"', $body);
        $this->assertStringContainsString('"title_visible":false', $body);
    }

    public function testRenderedPayloadIncludesExpandedAdSettings(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'video_id' => null,
            'force_disable_adblock' => true,
            'preroll_source_kind' => 'vast',
            'preroll_url' => 'https://ads.example/preroll.xml',
            'direct_play_mode' => 'iframe',
            'general_script_url' => 'https://ads.example/script.js',
            'midroll_cues' => json_encode([
                [
                    'trigger_kind' => 'percent',
                    'trigger_value' => 25,
                    'source_kind' => 'vast',
                    'url' => 'https://ads.example/mid.xml',
                    'skip_after' => 5,
                    'click_url' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->get("/watch/{$video['uuid']}");
        $body     = (string) $response->getBody();

        $this->assertStringContainsString('"force_disable_adblock":true', $body);
        $this->assertStringContainsString('"preroll_source_kind":"vast"', $body);
        $this->assertStringContainsString('"direct_play_mode":"iframe"', $body);
        $this->assertStringContainsString('"trigger_kind":"percent"', $body);
    }
}
