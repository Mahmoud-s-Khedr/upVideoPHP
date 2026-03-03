<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Player;

use VideoSystem\Auth\EmbedToken;
use VideoSystem\Player\PlayerTwigFactory;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * Public embed player route integration tests.
 *
 * GET /embed/{embedToken}
 * GET /embed/{embedToken}/bootstrap.json
 */
final class EmbedPlayerControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('embed_settings', 'subtitles', 'videos');
        PlayerTwigFactory::reset();
        EmbedToken::setTestNow(null);
    }

    protected function tearDown(): void
    {
        EmbedToken::setTestNow(null);
        PlayerTwigFactory::reset();
        $this->truncateTables('embed_settings', 'subtitles', 'videos');
        parent::tearDown();
    }

    public function testHtmlRouteReturns200AndSetsCspFrameAncestors(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $token = EmbedToken::sign($video['uuid'], 'https://client.example', '', 3600);

        $response = $this->get("/embed/{$token}");

        $this->assertStatus(200, $response);
        $this->assertHtmlResponse($response);
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(
            'frame-ancestors https://client.example',
            $response->getHeaderLine('Content-Security-Policy')
        );

        $body = (string) $response->getBody();
        $this->assertStringContainsString("mode: 'embed'", $body);
        $this->assertStringContainsString("/embed/{$token}/bootstrap.json", $body);
    }

    public function testHtmlRouteReturns403ForTamperedToken(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $token = EmbedToken::sign($video['uuid'], 'https://client.example', '', 3600);
        $bad   = substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A');

        $response = $this->get("/embed/{$bad}");

        $this->assertStatus(403, $response);
    }

    public function testHtmlRouteReturns403ForExpiredToken(): void
    {
        $now = time();
        EmbedToken::setTestNow($now);
        $video = $this->insertVideo(['status' => 'ready']);
        $token = EmbedToken::sign($video['uuid'], 'https://client.example', '', 10);
        EmbedToken::setTestNow($now + 20);

        $response = $this->get("/embed/{$token}");

        $this->assertStatus(403, $response);
    }

    public function testHtmlRouteReturns404ForMissingVideo(): void
    {
        $token    = EmbedToken::sign($this->newUuid(), 'https://client.example', '', 3600);
        $response = $this->get("/embed/{$token}");

        $this->assertStatus(404, $response);
    }

    public function testBootstrapReturnsPendingModeWhenNoOriginalOrHlsExists(): void
    {
        $video = $this->insertVideo(['status' => 'processing']);
        $token = EmbedToken::sign($video['uuid'], 'https://client.example', '', 3600);

        $response = $this->get("/embed/{$token}/bootstrap.json");
        $data     = $this->json($response);

        $this->assertStatus(200, $response);
        $this->assertSame('pending', $data['playback_mode']);
        $this->assertSame(15000, $data['poll_after_ms']);
        $this->assertArrayHasKey('audio_tracks', $data);
        $this->assertArrayHasKey('subtitle_tracks', $data);
        $this->assertArrayHasKey('processing_hls_url', $data);
    }

    public function testBootstrapReturnsOriginalModeWhenOriginalExists(): void
    {
        $video = $this->insertVideo(['status' => 'processing']);
        $key   = "videos/{$video['uuid']}/original.mp4";
        $this->b2->seed($key, 'video');

        \VideoSystem\Database\Connection::execute(
            'UPDATE videos SET original_b2_key = :key WHERE id = :id',
            [':key' => $key, ':id' => $video['id']]
        );

        $token    = EmbedToken::sign($video['uuid'], 'https://client.example', '', 3600);
        $response = $this->get("/embed/{$token}/bootstrap.json");
        $data     = $this->json($response);

        $this->assertSame('original', $data['playback_mode']);
        $this->assertStringContainsString($key, $data['original_url']);
        $this->assertSame(30000, $data['poll_after_ms']);
        $this->assertArrayHasKey('audio_tracks', $data);
    }

    public function testBootstrapReturnsHlsModeWhenVideoIsReady(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $token = EmbedToken::sign($video['uuid'], 'https://client.example', '', 3600);

        $response = $this->get("/embed/{$token}/bootstrap.json");
        $data     = $this->json($response);

        $this->assertSame('hls', $data['playback_mode']);
        $this->assertStringContainsString("/api/stream/{$video['uuid']}/master.m3u8?token=", $data['master_playlist_url']);
        $this->assertArrayHasKey('audio_tracks', $data);
    }

    public function testBootstrapUsesPerVideoEmbedSettingsBeforeGlobalDefault(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'video_id'      => null,
            'accent_color'  => '#00FF00',
            'title_visible' => false,
            'watch_top_banner_html' => '<div>global-banner</div>',
            'direct_play_url' => 'https://ads.example/global-play',
        ]);
        $this->insertEmbedSettings([
            'video_id'      => (int) $video['id'],
            'force_disable_adblock' => true,
            'preroll_source_kind' => 'vast',
            'preroll_url' => 'https://ads.example/preroll.xml',
            'postroll_source_kind' => 'mp4',
            'postroll_url' => 'https://ads.example/postroll.mp4',
            'midroll_cues' => json_encode([
                [
                    'trigger_kind' => 'percent',
                    'trigger_value' => 25,
                    'source_kind' => 'vast',
                    'url' => 'https://ads.example/midroll.xml',
                    'skip_after' => 5,
                    'click_url' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $token    = EmbedToken::sign($video['uuid'], 'https://client.example', '', 3600);
        $response = $this->get("/embed/{$token}/bootstrap.json");
        $data     = $this->json($response);

        $this->assertTrue($data['embed_settings']['force_disable_adblock']);
        $this->assertSame('vast', $data['embed_settings']['preroll_source_kind']);
        $this->assertSame('https://ads.example/preroll.xml', $data['embed_settings']['preroll_url']);
        $this->assertSame('mp4', $data['embed_settings']['postroll_source_kind']);
        $this->assertSame('<div>global-banner</div>', $data['embed_settings']['watch_top_banner_html']);
        $this->assertSame('https://ads.example/global-play', $data['embed_settings']['direct_play_url']);
        $this->assertSame('percent', $data['embed_settings']['midroll_cues'][0]['trigger_kind']);
    }

    public function testBootstrapFallsBackToGlobalEmbedSettings(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'video_id'      => null,
            'accent_color'  => '#00FF00',
            'title_visible' => false,
            'logo_position' => 'bottom-right',
        ]);

        $token    = EmbedToken::sign($video['uuid'], 'https://client.example', '', 3600);
        $response = $this->get("/embed/{$token}/bootstrap.json");
        $data     = $this->json($response);

        $this->assertSame('#00FF00', $data['embed_settings']['accent_color']);
        $this->assertFalse($data['embed_settings']['title_visible']);
        $this->assertSame('bottom-right', $data['embed_settings']['logo_position']);
    }

    public function testBootstrapFallsBackToHardcodedDefaultsWhenNoSettingsExist(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $token = EmbedToken::sign($video['uuid'], 'https://client.example', '', 3600);

        $response = $this->get("/embed/{$token}/bootstrap.json");
        $data     = $this->json($response);

        $this->assertSame('#FF0000', $data['embed_settings']['accent_color']);
        $this->assertTrue($data['embed_settings']['title_visible']);
        $this->assertSame('top-right', $data['embed_settings']['logo_position']);
        $this->assertFalse($data['embed_settings']['force_disable_adblock']);
        $this->assertSame('none', $data['embed_settings']['preroll_source_kind']);
        $this->assertSame([], $data['embed_settings']['midroll_cues']);
        $this->assertSame('popup', $data['embed_settings']['direct_play_mode']);
    }

    public function testBootstrapIncludesExpandedAdSettingsPayload(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'video_id' => null,
            'force_disable_adblock' => true,
            'preroll_source_kind' => 'vast',
            'preroll_url' => 'https://ads.example/preroll.xml',
            'preroll_skip_after' => 7,
            'postroll_source_kind' => 'mp4',
            'postroll_url' => 'https://ads.example/postroll.mp4',
            'watch_top_banner_html' => '<div>top</div>',
            'watch_bottom_banner_html' => '<div>bottom</div>',
            'embed_banner_html' => '<div>embed</div>',
            'general_script_url' => 'https://ads.example/global.js',
            'general_html_code' => '<div>general</div>',
            'direct_play_url' => 'https://ads.example/direct-play',
            'direct_play_mode' => 'iframe',
            'direct_popup_bypass_iframe' => false,
            'direct_download_url' => 'https://ads.example/direct-download',
            'direct_download_mode' => 'redirect',
            'midroll_cues' => json_encode([
                [
                    'time_sec' => 45,
                    'url' => 'https://ads.example/legacy.mp4',
                    'skip_after' => 4,
                    'click_url' => 'https://ads.example/click',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $token    = EmbedToken::sign($video['uuid'], 'https://client.example', '', 3600);
        $response = $this->get("/embed/{$token}/bootstrap.json");
        $data     = $this->json($response);

        $settings = $data['embed_settings'];
        $this->assertTrue($settings['force_disable_adblock']);
        $this->assertSame('vast', $settings['preroll_source_kind']);
        $this->assertSame(7, $settings['preroll_skip_after']);
        $this->assertSame('<div>embed</div>', $settings['embed_banner_html']);
        $this->assertSame('https://ads.example/global.js', $settings['general_script_url']);
        $this->assertSame('iframe', $settings['direct_play_mode']);
        $this->assertFalse($settings['direct_popup_bypass_iframe']);
        $this->assertSame('redirect', $settings['direct_download_mode']);
        $this->assertSame('seconds', $settings['midroll_cues'][0]['trigger_kind']);
        $this->assertSame(45, $settings['midroll_cues'][0]['trigger_value']);
        $this->assertSame('mp4', $settings['midroll_cues'][0]['source_kind']);
    }

    public function testBootstrapIncludesOnlySubtitleTracksThatCanBeStreamed(): void
    {
        $video      = $this->insertVideo(['status' => 'ready']);
        $goodSubKey = "videos/{$video['uuid']}/subs/en.vtt";
        $this->b2->seed($goodSubKey, 'WEBVTT');

        \VideoSystem\Database\Connection::execute(
            'INSERT INTO subtitles (video_id, track_index, language_code, label, is_forced, b2_vtt_key)
             VALUES (:vid1, 0, :lang1, :label1, 0, :key1),
                    (:vid2, 1, :lang2, :label2, 0, :key2)',
            [
                ':vid1'   => $video['id'],
                ':vid2'   => $video['id'],
                ':lang1'  => 'en',
                ':label1' => 'English',
                ':key1'   => $goodSubKey,
                ':lang2'  => 'fr',
                ':label2' => 'French',
                ':key2'   => "videos/{$video['uuid']}/subs/fr.vtt",
            ]
        );

        $token    = EmbedToken::sign($video['uuid'], 'https://client.example', '', 3600);
        $response = $this->get("/embed/{$token}/bootstrap.json");
        $data     = $this->json($response);

        $this->assertCount(1, $data['subtitle_tracks']);
        $this->assertSame('en', $data['subtitle_tracks'][0]['language_code']);
        $this->assertSame(0, $data['subtitle_tracks'][0]['track_index']);
        $this->assertStringContainsString(
            "/api/stream/{$video['uuid']}/subtitles/0.vtt?token=",
            $data['subtitle_tracks'][0]['src']
        );
    }
}
