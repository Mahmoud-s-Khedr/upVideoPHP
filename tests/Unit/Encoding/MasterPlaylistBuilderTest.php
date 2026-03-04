<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Encoding;

use PHPUnit\Framework\TestCase;
use VideoSystem\Encoding\MasterPlaylistBuilder;
use VideoSystem\Storage\B2Client;
use VideoSystem\Tests\Support\FakeB2Client;

/**
 * MasterPlaylistBuilder unit tests.
 *
 * DB reads (audio_tracks, subtitles) are intercepted by
 * MasterPlaylistBuilder::setTestData().
 * B2 uploads are intercepted by FakeB2Client.
 *
 * All tests exercise the pure playlist-building logic without a live DB.
 */
final class MasterPlaylistBuilderTest extends TestCase
{
    private FakeB2Client $b2;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->b2 = new FakeB2Client();
        B2Client::setTestOverride($this->b2);

        $this->tmpDir = sys_get_temp_dir() . '/mb_test_' . uniqid();
        mkdir($this->tmpDir, 0750, recursive: true);

        // Default: no audio, no subtitles — matches most of the test cases
        MasterPlaylistBuilder::setTestData([], []);
    }

    protected function tearDown(): void
    {
        B2Client::setTestOverride(null);
        MasterPlaylistBuilder::setTestData(null, null);
        $this->rimraf($this->tmpDir);
    }

    private function rimraf(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rimraf($path . '/' . $entry);
        }
        rmdir($path);
    }

    /**
     * Build a master playlist for the given rendition labels and return the
     * B2 content string for further assertions.
     */
    private function build(string $uuid, array $renditionLabels): string
    {
        $builder = new MasterPlaylistBuilder(1, $uuid);
        $builder->build($this->tmpDir, $renditionLabels);
        return $this->b2->read("videos/{$uuid}/master.m3u8");
    }

    // =========================================================================
    // Structural requirements
    // =========================================================================

    public function testMasterM3u8IsUploadedToB2(): void
    {
        $this->build('uuid-basic', ['720p']);
        $this->assertTrue($this->b2->hasKey('videos/uuid-basic/master.m3u8'));
    }

    public function testPlaylistFileIsWrittenToProcessingDir(): void
    {
        $this->build('uuid-file', ['720p']);
        $this->assertFileExists($this->tmpDir . '/master.m3u8');
    }

    public function testPlaylistStartsWithExtm3uHeader(): void
    {
        $content = $this->build('uuid-header', ['720p']);
        $this->assertStringStartsWith('#EXTM3U', $content);
    }

    // =========================================================================
    // Video stream entries
    // =========================================================================

    public function testSingleRenditionProducesOneStreamInfEntry(): void
    {
        $content = $this->build('uuid-single', ['720p']);

        $count = substr_count($content, '#EXT-X-STREAM-INF');
        $this->assertSame(1, $count);
        $this->assertStringContainsString('720p/index.m3u8', $content);
    }

    public function testAllFiveRenditionsProduceFiveStreamInfEntries(): void
    {
        $labels  = ['1080p', '720p', '540p', '480p', '360p'];
        $content = $this->build('uuid-all-five', $labels);

        $this->assertSame(
            5,
            substr_count($content, '#EXT-X-STREAM-INF'),
            'Expected one #EXT-X-STREAM-INF per rendition'
        );

        foreach ($labels as $label) {
            $this->assertStringContainsString($label . '/index.m3u8', $content);
        }
    }

    public function testStreamInfContainsBandwidthAttribute(): void
    {
        $content = $this->build('uuid-bw', ['1080p']);
        $this->assertMatchesRegularExpression('/BANDWIDTH=\d+/', $content);
    }

    public function testStreamInfContainsResolutionForKnownLabels(): void
    {
        $expectations = [
            '1080p' => 'RESOLUTION=1920x1080',
            '720p'  => 'RESOLUTION=1280x720',
            '540p'  => 'RESOLUTION=960x540',
            '480p'  => 'RESOLUTION=854x480',
            '360p'  => 'RESOLUTION=640x360',
        ];

        foreach ($expectations as $label => $expectedResolution) {
            MasterPlaylistBuilder::setTestData([], []);
            $content = $this->build('uuid-res-' . $label, [$label]);
            $this->assertStringContainsString($expectedResolution, $content, "Resolution mismatch for {$label}");
        }
    }

    public function testStreamInfHasNoAudioAttributeWhenNoAudioTracks(): void
    {
        $content = $this->build('uuid-no-audio', ['720p']);
        $this->assertStringNotContainsString('AUDIO=', $content);
    }

    public function testStreamInfHasNoSubtitlesAttributeWhenNoSubtitles(): void
    {
        $content = $this->build('uuid-no-subs', ['720p']);
        $this->assertStringNotContainsString('SUBTITLES=', $content);
    }

    // =========================================================================
    // Audio track entries
    // =========================================================================

    public function testAudioTracksProduceExtXMediaTypeAudioEntries(): void
    {
        MasterPlaylistBuilder::setTestData(
            [
                ['track_index' => 0, 'language_code' => 'eng', 'label' => 'English'],
                ['track_index' => 1, 'language_code' => 'spa', 'label' => 'Spanish'],
            ],
            []
        );

        $content = $this->build('uuid-audio', ['720p']);

        $this->assertStringContainsString('#EXT-X-MEDIA:TYPE=AUDIO', $content);
        $this->assertStringContainsString('LANGUAGE="eng"', $content);
        $this->assertStringContainsString('LANGUAGE="spa"', $content);
        $this->assertStringContainsString('NAME="English"', $content);
        $this->assertStringContainsString('NAME="Spanish"', $content);
        $this->assertStringContainsString('URI="audio_0/index.m3u8"', $content);
        $this->assertStringContainsString('URI="audio_1/index.m3u8"', $content);
    }

    public function testPlaylistUsesExactTrackLabelsWithoutNormalization(): void
    {
        MasterPlaylistBuilder::setTestData(
            [
                ['track_index' => 0, 'language_code' => 'jpn', 'label' => 'AAC 2.0 @ 192kb/s - [Japanese]'],
            ],
            [
                ['language_code' => 'eng', 'label' => 'Honorifics [Kaleido] - [enn]', 'is_forced' => false],
            ]
        );

        $content = $this->build('uuid-exact-labels', ['720p']);

        $this->assertStringContainsString('NAME="AAC 2.0 @ 192kb/s - [Japanese]"', $content);
        $this->assertStringContainsString('NAME="Honorifics [Kaleido] - [enn]"', $content);
    }

    public function testFirstAudioTrackIsDefaultRestAreNot(): void
    {
        MasterPlaylistBuilder::setTestData(
            [
                ['track_index' => 0, 'language_code' => 'eng', 'label' => 'English'],
                ['track_index' => 1, 'language_code' => 'spa', 'label' => 'Spanish'],
                ['track_index' => 2, 'language_code' => 'fra', 'label' => 'French'],
            ],
            []
        );

        $content    = $this->build('uuid-default-audio', ['720p']);
        $audioLines = array_values(
            array_filter(
                explode("\n", $content),
                fn(string $l) => str_starts_with($l, '#EXT-X-MEDIA:TYPE=AUDIO')
            )
        );

        $this->assertStringContainsString('DEFAULT=YES', $audioLines[0], 'Track 0 should be DEFAULT=YES');
        $this->assertStringContainsString('DEFAULT=NO',  $audioLines[1], 'Track 1 should be DEFAULT=NO');
        $this->assertStringContainsString('DEFAULT=NO',  $audioLines[2], 'Track 2 should be DEFAULT=NO');
    }

    public function testStreamInfHasAudioAttributeWhenAudioTracksPresent(): void
    {
        MasterPlaylistBuilder::setTestData(
            [['track_index' => 0, 'language_code' => 'eng', 'label' => 'English']],
            []
        );

        $content = $this->build('uuid-audio-attr', ['720p']);
        $this->assertStringContainsString('AUDIO="audio"', $content);
    }

    // =========================================================================
    // Subtitle track entries
    // =========================================================================

    public function testSubtitleTracksProduceExtXMediaTypeSubtitlesEntries(): void
    {
        MasterPlaylistBuilder::setTestData(
            [],
            [
                ['track_index' => 0, 'language_code' => 'eng', 'label' => 'English', 'is_forced' => false],
                ['track_index' => 1, 'language_code' => 'fra', 'label' => 'French',  'is_forced' => false],
            ]
        );

        $content = $this->build('uuid-subs', ['720p']);

        $this->assertStringContainsString('#EXT-X-MEDIA:TYPE=SUBTITLES', $content);
        $this->assertStringContainsString('LANGUAGE="eng"', $content);
        $this->assertStringContainsString('LANGUAGE="fra"', $content);
        $this->assertStringContainsString('URI="subs/eng_0.m3u8"', $content);
        $this->assertStringContainsString('URI="subs/fra_1.m3u8"', $content);
    }

    public function testForcedSubtitleHasForcedYesAndDefaultYes(): void
    {
        MasterPlaylistBuilder::setTestData(
            [],
            [['language_code' => 'eng', 'label' => 'Forced English', 'is_forced' => true]]
        );

        $content = $this->build('uuid-forced-sub', ['720p']);
        $this->assertStringContainsString('FORCED=YES', $content);
        $this->assertStringContainsString('DEFAULT=YES', $content);
        $this->assertStringContainsString('AUTOSELECT=YES', $content);
    }

    public function testNonForcedSubtitleHasForcedNo(): void
    {
        MasterPlaylistBuilder::setTestData(
            [],
            [['language_code' => 'eng', 'label' => 'English', 'is_forced' => false]]
        );

        $content = $this->build('uuid-nonfoc-sub', ['720p']);
        $this->assertStringContainsString('FORCED=NO', $content);
    }

    public function testStreamInfHasSubtitlesAttributeWhenSubtitlesPresent(): void
    {
        MasterPlaylistBuilder::setTestData(
            [],
            [['language_code' => 'eng', 'label' => 'English', 'is_forced' => false]]
        );

        $content = $this->build('uuid-subs-attr', ['720p']);
        $this->assertStringContainsString('SUBTITLES="subs"', $content);
    }

    // =========================================================================
    // Combined audio + subtitles
    // =========================================================================

    public function testStreamInfHasBothAudioAndSubtitlesAttributesWhenBothPresent(): void
    {
        MasterPlaylistBuilder::setTestData(
            [['track_index' => 0, 'language_code' => 'eng', 'label' => 'English']],
            [['language_code' => 'eng', 'label' => 'English', 'is_forced' => false]]
        );

        $content = $this->build('uuid-both', ['720p']);
        $this->assertStringContainsString('AUDIO="audio"',    $content);
        $this->assertStringContainsString('SUBTITLES="subs"', $content);
    }
}
