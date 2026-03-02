<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Player;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Player\EmbedSettingsLoader;

#[CoversClass(EmbedSettingsLoader::class)]
final class EmbedSettingsLoaderTest extends TestCase
{
    private EmbedSettingsLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new EmbedSettingsLoader();
    }

    public function testNormalizeConvertsLegacyMidrollCueShape(): void
    {
        $settings = $this->loader->normalize([
            'midroll_cues' => json_encode([
                [
                    'time_sec' => 30,
                    'url' => 'https://example.com/ad.mp4',
                    'skip_after' => 6,
                    'click_url' => 'https://example.com/click',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame([
            [
                'trigger_kind' => 'seconds',
                'trigger_value' => 30,
                'source_kind' => 'mp4',
                'url' => 'https://example.com/ad.mp4',
                'skip_after' => 6,
                'click_url' => 'https://example.com/click',
            ],
        ], $settings['midroll_cues']);
    }

    public function testNormalizeDropsInvalidPercentCueAndKeepsValidNewShape(): void
    {
        $settings = $this->loader->normalize([
            'midroll_cues' => json_encode([
                [
                    'trigger_kind' => 'percent',
                    'trigger_value' => 150,
                    'source_kind' => 'vast',
                    'url' => 'https://example.com/bad.xml',
                    'skip_after' => 5,
                ],
                [
                    'trigger_kind' => 'percent',
                    'trigger_value' => 25,
                    'source_kind' => 'vast',
                    'url' => 'https://example.com/good.xml',
                    'skip_after' => 5,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertCount(1, $settings['midroll_cues']);
        self::assertSame('percent', $settings['midroll_cues'][0]['trigger_kind']);
        self::assertSame(25, $settings['midroll_cues'][0]['trigger_value']);
        self::assertSame('vast', $settings['midroll_cues'][0]['source_kind']);
    }

    public function testNormalizeInfersLegacyMp4SourceKindFromPrerollUrl(): void
    {
        $settings = $this->loader->normalize([
            'preroll_url' => 'https://example.com/preroll.mp4',
            'preroll_source_kind' => null,
        ]);

        self::assertSame('mp4', $settings['preroll_source_kind']);
    }

    public function testNormalizeCoercesBooleansAndEmptyRawCode(): void
    {
        $settings = $this->loader->normalize([
            'title_visible' => 0,
            'force_disable_adblock' => 1,
            'direct_popup_bypass_iframe' => 0,
            'watch_top_banner_html' => '  ',
            'general_html_code' => '',
        ]);

        self::assertFalse($settings['title_visible']);
        self::assertTrue($settings['force_disable_adblock']);
        self::assertFalse($settings['direct_popup_bypass_iframe']);
        self::assertNull($settings['watch_top_banner_html']);
        self::assertNull($settings['general_html_code']);
    }

    public function testNormalizeFallsBackToSafeModes(): void
    {
        $settings = $this->loader->normalize([
            'direct_play_mode' => 'bad',
            'direct_download_mode' => 'bad',
            'postroll_source_kind' => 'wat',
        ]);

        self::assertSame('popup', $settings['direct_play_mode']);
        self::assertSame('popup', $settings['direct_download_mode']);
        self::assertSame('none', $settings['postroll_source_kind']);
    }
}
