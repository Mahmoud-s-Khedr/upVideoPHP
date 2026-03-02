<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Streaming;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Streaming\PlaylistRewriter;

/**
 * Pure string-in / string-out tests — no database, no B2, no HTTP calls.
 */
#[CoversClass(PlaylistRewriter::class)]
final class PlaylistRewriterTest extends TestCase
{
    private string $uuid    = 'aaaabbbb-cccc-dddd-eeee-ffffffffffff';
    private string $baseUrl = 'https://example.com';

    private function make(): PlaylistRewriter
    {
        return new PlaylistRewriter($this->uuid, $this->baseUrl);
    }

    // =========================================================================
    // rewriteMaster()
    // =========================================================================

    private function sampleMaster(): string
    {
        return <<<M3U8
            #EXTM3U
            #EXT-X-VERSION:6
            #EXT-X-STREAM-INF:BANDWIDTH=4500000,RESOLUTION=1920x1080
            1080p/index.m3u8
            #EXT-X-STREAM-INF:BANDWIDTH=2700000,RESOLUTION=1280x720
            720p/index.m3u8
            #EXT-X-STREAM-INF:BANDWIDTH=1300000,RESOLUTION=854x480
            480p/index.m3u8
            M3U8;
    }

    public function testRewriteMasterReplacesRenditionUris(): void
    {
        $rewritten = $this->make()->rewriteMaster($this->sampleMaster());

        self::assertStringContainsString(
            "https://example.com/api/stream/{$this->uuid}/1080p/index.m3u8",
            $rewritten
        );
        self::assertStringContainsString(
            "https://example.com/api/stream/{$this->uuid}/720p/index.m3u8",
            $rewritten
        );
        self::assertStringContainsString(
            "https://example.com/api/stream/{$this->uuid}/480p/index.m3u8",
            $rewritten
        );
    }

    public function testRewriteMasterNoLongerContainsRelativeM3u8Paths(): void
    {
        $rewritten = $this->make()->rewriteMaster($this->sampleMaster());

        // Each line in the rewritten playlist that contained a relative URI must
        // now start with https:// (not a bare "720p/index.m3u8" standalone path).
        foreach (explode("\n", $rewritten) as $line) {
            $trimmed = ltrim($line);
            if (str_ends_with($trimmed, '.m3u8') && !str_starts_with($trimmed, '#')) {
                self::assertStringStartsWith('https://', $trimmed,
                    "Expected absolute URL but found: {$trimmed}"
                );
            }
        }
    }

    public function testRewriteMasterAppendsTokenParamWhenProvided(): void
    {
        $rewritten = $this->make()->rewriteMaster($this->sampleMaster(), 'tok123');

        self::assertStringContainsString('?token=tok123', $rewritten);
    }

    public function testRewriteMasterDoesNotAppendTokenWhenNull(): void
    {
        $rewritten = $this->make()->rewriteMaster($this->sampleMaster(), null);

        self::assertStringNotContainsString('token=', $rewritten);
    }

    public function testRewriteMasterPreservesNonUriLines(): void
    {
        $rewritten = $this->make()->rewriteMaster($this->sampleMaster());

        self::assertStringContainsString('#EXTM3U', $rewritten);
        self::assertStringContainsString('#EXT-X-VERSION:6', $rewritten);
        self::assertStringContainsString('#EXT-X-STREAM-INF:', $rewritten);
    }

    public function testRewriteMasterHandlesSubtitleEXTXMedia(): void
    {
        $playlist = <<<M3U8
            #EXTM3U
            #EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="English",URI="subs/en.vtt"
            #EXT-X-STREAM-INF:BANDWIDTH=2700000,SUBTITLES="subs"
            720p/index.m3u8
            M3U8;

        $rewritten = $this->make()->rewriteMaster($playlist);

        // The subtitle URI should be rewritten
        self::assertStringNotContainsString('URI="subs/en.vtt"', $rewritten);
    }

    // =========================================================================
    // rewriteRendition()
    // =========================================================================

    private function sampleRendition(): string
    {
        return <<<M3U8
            #EXTM3U
            #EXT-X-VERSION:6
            #EXT-X-KEY:METHOD=AES-128,URI="https://example.com/api/keys/aaaabbbb-cccc-dddd-eeee-ffffffffffff/0",IV=0x00000000000000000000000000000000
            #EXT-X-TARGETDURATION:6
            #EXTINF:6.0,
            seg00001.ts
            #EXTINF:6.0,
            seg00002.ts
            #EXTINF:3.5,
            seg00003.ts
            #EXT-X-ENDLIST
            M3U8;
    }

    public function testRewriteRenditionReplacesSegmentUris(): void
    {
        $rewritten = $this->make()->rewriteRendition($this->sampleRendition(), '720p');

        self::assertStringContainsString(
            "https://example.com/api/stream/{$this->uuid}/720p/seg00001.ts",
            $rewritten
        );
        self::assertStringContainsString(
            "https://example.com/api/stream/{$this->uuid}/720p/seg00002.ts",
            $rewritten
        );
        self::assertStringContainsString(
            "https://example.com/api/stream/{$this->uuid}/720p/seg00003.ts",
            $rewritten
        );
    }

    public function testRewriteRenditionNoLongerContainsRawSegmentFilenames(): void
    {
        $rewritten = $this->make()->rewriteRendition($this->sampleRendition(), '720p');

        self::assertStringNotContainsString("\nseg00001.ts", $rewritten);
    }

    public function testRewriteRenditionRewritesKeyUri(): void
    {
        $rewritten = $this->make()->rewriteRendition($this->sampleRendition(), '720p');

        self::assertStringContainsString(
            "URI=\"https://example.com/api/keys/{$this->uuid}/0\"",
            $rewritten
        );
    }

    public function testRewriteRenditionAppendsTokenToKeyUri(): void
    {
        $rewritten = $this->make()->rewriteRendition($this->sampleRendition(), '720p', 'mytoken');

        self::assertStringContainsString('?token=mytoken', $rewritten);
    }

    public function testRewriteRenditionDoesNotAppendTokenWhenNull(): void
    {
        $rewritten = $this->make()->rewriteRendition($this->sampleRendition(), '720p', null);

        self::assertStringNotContainsString('token=', $rewritten);
    }

    public function testRewriteRenditionPreservesNonUriLines(): void
    {
        $rewritten = $this->make()->rewriteRendition($this->sampleRendition(), '720p');

        self::assertStringContainsString('#EXTM3U', $rewritten);
        self::assertStringContainsString('#EXT-X-ENDLIST', $rewritten);
        self::assertStringContainsString('#EXT-X-TARGETDURATION:6', $rewritten);
        self::assertStringContainsString('#EXTINF:6.0', $rewritten);
    }

    public function testRewriteRenditionWorksForAllLabels(): void
    {
        foreach (['1080p', '720p', '480p', '360p'] as $label) {
            $rewritten = $this->make()->rewriteRendition($this->sampleRendition(), $label);

            self::assertStringContainsString(
                "/api/stream/{$this->uuid}/{$label}/seg00001.ts",
                $rewritten,
                "Label {$label} not rewritten correctly."
            );
        }
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testRewriteMasterEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->make()->rewriteMaster(''));
    }

    public function testRewriteRenditionEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->make()->rewriteRendition('', '720p'));
    }

    public function testDifferentUuidsProduceDifferentUrls(): void
    {
        $r1 = new PlaylistRewriter('uuid-one', $this->baseUrl);
        $r2 = new PlaylistRewriter('uuid-two', $this->baseUrl);

        $rewritten1 = $r1->rewriteRendition($this->sampleRendition(), '720p');
        $rewritten2 = $r2->rewriteRendition($this->sampleRendition(), '720p');

        self::assertStringContainsString('uuid-one', $rewritten1);
        self::assertStringContainsString('uuid-two', $rewritten2);
        self::assertStringNotContainsString('uuid-two', $rewritten1);
        self::assertStringNotContainsString('uuid-one', $rewritten2);
    }
}
