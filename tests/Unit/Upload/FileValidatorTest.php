<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VideoSystem\Upload\FileValidator;
use VideoSystem\Upload\ValidationException;

/**
 * FileValidator — stages 1, 2, and 3 (no ffprobe invocation needed).
 *
 * Stages 4 and 5 are tested in the integration suite
 * (VideoSystem\Tests\Integration\Upload\UploadControllerTest) where a real
 * or stubbed ffprobe binary is available.
 */
final class FileValidatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fv_test_' . getmypid();
        @mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        @rmdir($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a normalised $_FILES entry from a real temp file.
     *
     * @param string $content Binary content to write (magic bytes etc.)
     * @param string $mime    Declared MIME type
     * @param int    $error   PHP upload error code (default UPLOAD_ERR_OK)
     */
    private function makeEntry(
        string $content,
        string $mime = 'video/mp4',
        int    $error = UPLOAD_ERR_OK,
        ?int   $sizeOverride = null,
        string $name = 'test.mp4',
    ): array {
        $path = $this->tmpDir . '/upload_' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);

        return [
            'name'     => $name,
            'type'     => $mime,
            'tmp_name' => $path,
            'error'    => $error,
            'size'     => $sizeOverride ?? strlen($content),
        ];
    }

    private function makeFfprobeStub(string $formatOutput, string $videoStreamOutput): string
    {
        $path = $this->tmpDir . '/ffprobe_stub_' . bin2hex(random_bytes(4)) . '.sh';
        $script = <<<SH
#!/bin/sh
case "$*" in
  *format=format_name*)
    printf '%%s\n' %s
    ;;
  *stream=codec_type*)
    printf '%%s\n' %s
    ;;
  *)
    exit 1
    ;;
esac
SH;

        $script = sprintf(
            $script,
            escapeshellarg($formatOutput),
            escapeshellarg($videoStreamOutput),
        );

        file_put_contents($path, $script);
        chmod($path, 0755);

        return $path;
    }

    /** Real MP4 magic bytes (ftyp at offset 4 with moov container magic). */
    private function mp4Magic(): string
    {
        // Bytes 0-3: box size (ignored by magic checker)
        // Bytes 4-7: 'ftyp'
        return "\x00\x00\x00\x18" . 'ftyp' . 'isom' . "\x00\x00\x00\x00" . 'isom' . 'iso2';
    }

    /** Real MKV magic bytes. */
    private function mkvMagic(): string
    {
        return "\x1A\x45\xDF\xA3" . str_repeat("\x00", 20);
    }

    // =========================================================================
    // Stage 1 — Size checks
    // =========================================================================

    public function testThrowsOnUploadErrIniSize(): void
    {
        $entry = $this->makeEntry($this->mp4Magic(), error: UPLOAD_ERR_INI_SIZE);
        $this->expectException(ValidationException::class);
        $v = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
        } catch (ValidationException $e) {
            $this->assertSame('FILE_TOO_LARGE', $e->getErrorCode());
            $this->assertSame(413, $e->getHttpStatus());
            throw $e;
        }
    }

    public function testThrowsOnUploadErrFormSize(): void
    {
        $entry = $this->makeEntry($this->mp4Magic(), error: UPLOAD_ERR_FORM_SIZE);

        $this->expectException(ValidationException::class);
        $v = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
        } catch (ValidationException $e) {
            $this->assertSame('FILE_TOO_LARGE', $e->getErrorCode());
            $this->assertSame(413, $e->getHttpStatus());
            throw $e;
        }
    }

    public function testThrowsOnOtherUploadError(): void
    {
        $entry = $this->makeEntry($this->mp4Magic(), error: UPLOAD_ERR_PARTIAL);

        $this->expectException(ValidationException::class);
        $v = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
        } catch (ValidationException $e) {
            $this->assertSame('UPLOAD_ERROR', $e->getErrorCode());
            throw $e;
        }
    }

    public function testThrowsWhenSizeExceedsLimit(): void
    {
        // phpunit.xml sets MAX_UPLOAD_BYTES=8589934592  (8 GB); pass a size just above it
        $entry          = $this->makeEntry($this->mp4Magic());
        $entry['size']  = 8589934592 + 1;

        $this->expectException(ValidationException::class);
        $v = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
        } catch (ValidationException $e) {
            $this->assertSame('FILE_TOO_LARGE', $e->getErrorCode());
            $this->assertSame(413, $e->getHttpStatus());
            throw $e;
        }
    }

    public function testNoExceptionWhenSizeEqualsLimit(): void
    {
        // At exactly the limit the validator should advance past stage 1.
        // It will then fail stage 2 (MIME) or stage 3 (magic), which is fine.
        $entry         = $this->makeEntry('bad-content', mime: 'application/octet-stream');
        $entry['size'] = 8589934592; // exactly at limit

        $v = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
            $this->fail('Expected ValidationException for bad MIME, not for size');
        } catch (ValidationException $e) {
            $this->assertNotSame('FILE_TOO_LARGE', $e->getErrorCode(),
                'Stage 1 should have passed — error should be INVALID_MIME, not FILE_TOO_LARGE');
        }
    }

    // =========================================================================
    // Stage 2 — MIME allowlist
    // =========================================================================

    #[DataProvider('disallowedMimeProvider')]
    public function testThrowsOnDisallowedMime(string $mime): void
    {
        $entry = $this->makeEntry($this->mp4Magic(), mime: $mime);
        $v     = new FileValidator('/usr/bin/ffprobe');

        $this->expectException(ValidationException::class);

        try {
            $v->validate($entry);
        } catch (ValidationException $e) {
            $this->assertSame('INVALID_MIME', $e->getErrorCode());
            $this->assertSame(422, $e->getHttpStatus());
            throw $e;
        }
    }

    public static function disallowedMimeProvider(): array
    {
        return [
            'text/plain'        => ['text/plain'],
            'application/pdf'   => ['application/pdf'],
            'image/jpeg'        => ['image/jpeg'],
            'video/x-flv'       => ['video/x-flv'],
        ];
    }

    #[DataProvider('allowedMimeProvider')]
    public function testAllowedMimesPassStage2(string $mime): void
    {
        // A valid MIME but random bytes will fail stage 3 (magic bytes), not stage 2
        $entry = $this->makeEntry(str_repeat("\x00", 50), mime: $mime);
        $v     = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
            $this->fail('Expected ValidationException for magic bytes, not a pass-through');
        } catch (ValidationException $e) {
            $this->assertNotSame('INVALID_MIME', $e->getErrorCode(),
                "MIME '{$mime}' should be allowed — got INVALID_MIME instead of INVALID_FILE_MAGIC");
        }
    }

    public static function allowedMimeProvider(): array
    {
        return [
            'mp4'       => ['video/mp4'],
            'mkv'       => ['video/x-matroska'],
            'ts'        => ['video/mp2t'],
            'avi'       => ['video/x-msvideo'],
            'avi vnd'   => ['video/vnd.avi'],
            'quicktime' => ['video/quicktime'],
            'webm'      => ['video/webm'],
        ];
    }

    public function testMimeCheckIsCaseInsensitive(): void
    {
        $entry = $this->makeEntry(str_repeat("\x00", 50), mime: 'VIDEO/MP4');
        $v     = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
            $this->fail('Expected ValidationException for magic bytes');
        } catch (ValidationException $e) {
            $this->assertNotSame('INVALID_MIME', $e->getErrorCode());
        }
    }

    public function testTsFilenameWithBlankMimePassesStage2(): void
    {
        $entry = $this->makeEntry(str_repeat("\x00", 50), mime: '', name: 'sample.ts');
        $v     = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
            $this->fail('Expected validation to fail after stage 2 due to magic bytes.');
        } catch (ValidationException $e) {
            $this->assertNotSame('INVALID_MIME', $e->getErrorCode());
        }
    }

    public function testTsFilenameWithGenericMimePassesStage2(): void
    {
        $entry = $this->makeEntry(str_repeat("\x00", 50), mime: 'application/octet-stream', name: 'sample.ts');
        $v     = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
            $this->fail('Expected validation to fail after stage 2 due to magic bytes.');
        } catch (ValidationException $e) {
            $this->assertNotSame('INVALID_MIME', $e->getErrorCode());
        }
    }

    public function testExplicitWrongMimeStillFailsEvenWithTsExtension(): void
    {
        $entry = $this->makeEntry($this->mp4Magic(), mime: 'text/plain', name: 'sample.ts');
        $v     = new FileValidator('/usr/bin/ffprobe');

        $this->expectException(ValidationException::class);

        try {
            $v->validate($entry);
        } catch (ValidationException $e) {
            $this->assertSame('INVALID_MIME', $e->getErrorCode());
            throw $e;
        }
    }

    public function testGenericMimeWithUnsupportedExtensionFailsStage2(): void
    {
        $entry = $this->makeEntry($this->mp4Magic(), mime: 'application/octet-stream', name: 'sample.flv');
        $v     = new FileValidator('/usr/bin/ffprobe');

        $this->expectException(ValidationException::class);

        try {
            $v->validate($entry);
        } catch (ValidationException $e) {
            $this->assertSame('INVALID_MIME', $e->getErrorCode());
            throw $e;
        }
    }

    // =========================================================================
    // Stage 3 — Magic bytes
    // =========================================================================

    public function testThrowsWhenMagicBytesFail(): void
    {
        // Valid MIME, but content is just ASCII text — not a video container
        $entry = $this->makeEntry('This is not a video file at all', mime: 'video/mp4');
        $v     = new FileValidator('/usr/bin/ffprobe');

        $this->expectException(ValidationException::class);

        try {
            $v->validate($entry);
        } catch (ValidationException $e) {
            $this->assertSame('INVALID_FILE_MAGIC', $e->getErrorCode());
            $this->assertSame(422, $e->getHttpStatus());
            throw $e;
        }
    }

    public function testThrowsOnRandomBinaryContent(): void
    {
        $entry = $this->makeEntry(random_bytes(100), mime: 'video/mp4');
        $v     = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            // Should fail at magic bytes or ffprobe — either way not a size/MIME error
            $this->assertNotSame('FILE_TOO_LARGE', $e->getErrorCode());
            $this->assertNotSame('INVALID_MIME', $e->getErrorCode());
        }
    }

    #[DataProvider('magicByteContainerProvider')]
    public function testCorrectMagicBytesPassStage3(string $mime, string $magicContent): void
    {
        // Files with real magic bytes will pass stage 3 and then fail at stage 4 (ffprobe)
        // because the content is truncated — that is expected.
        $entry = $this->makeEntry($magicContent, mime: $mime);
        $v     = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
            // If ffprobe happens to be unavailable on this machine, all after stage 3 may pass vacuously
        } catch (ValidationException $e) {
            $this->assertNotSame('INVALID_FILE_MAGIC', $e->getErrorCode(),
                "Magic bytes for {$mime} should have passed stage 3; got INVALID_FILE_MAGIC");
        }
    }

    public static function magicByteContainerProvider(): array
    {
        $mp4Magic  = "\x00\x00\x00\x18" . 'ftyp' . 'isom' . "\x00\x00\x00\x00isoiso2";
        $mkvMagic  = "\x1A\x45\xDF\xA3" . str_repeat("\x00", 20);
        $webmMagic = "\x1A\x45\xDF\xA3" . str_repeat("\x00", 20); // WebM shares EBML magic
        $aviMagic  = 'RIFF' . "\x00\x00\x00\x00" . 'AVI ' . str_repeat("\x00", 8);
        $tsMagic   = "\x47" . str_repeat("\x00", 187); // 188-byte TS packet

        return [
            'mp4'  => ['video/mp4',         $mp4Magic],
            'mkv'  => ['video/x-matroska',  $mkvMagic],
            'webm' => ['video/webm',         $webmMagic],
            'avi'  => ['video/x-msvideo',    $aviMagic],
            'ts'   => ['video/mp2t',         $tsMagic],
        ];
    }

    // =========================================================================
    // Stages 4 and 5 — ffprobe output handling
    // =========================================================================

    public function testQuotedMatroskaWebmFormatPassesValidation(): void
    {
        $entry = $this->makeEntry($this->mkvMagic(), mime: 'video/x-matroska');
        $v     = new FileValidator($this->makeFfprobeStub('"matroska,webm"', 'video'));

        $v->validate($entry);
        $this->addToAssertionCount(1);
    }

    public function testCommaSeparatedMp4FormatsPassValidation(): void
    {
        $entry = $this->makeEntry($this->mp4Magic(), mime: 'video/mp4');
        $v     = new FileValidator($this->makeFfprobeStub('mov,mp4,m4a,3gp,3g2,mj2', 'video'));

        $v->validate($entry);
        $this->addToAssertionCount(1);
    }

    public function testUnknownFfprobeFormatFailsValidation(): void
    {
        $entry = $this->makeEntry($this->mp4Magic(), mime: 'video/mp4');
        $v     = new FileValidator($this->makeFfprobeStub('flv', 'video'));

        $this->expectException(ValidationException::class);

        try {
            $v->validate($entry);
        } catch (ValidationException $e) {
            $this->assertSame('INVALID_VIDEO', $e->getErrorCode());
            throw $e;
        }
    }

    public function testMissingVideoStreamFailsValidation(): void
    {
        $entry = $this->makeEntry($this->mkvMagic(), mime: 'video/x-matroska');
        $v     = new FileValidator($this->makeFfprobeStub('matroska,webm', ''));

        $this->expectException(ValidationException::class);

        try {
            $v->validate($entry);
        } catch (ValidationException $e) {
            $this->assertSame('NO_VIDEO_STREAM', $e->getErrorCode());
            throw $e;
        }
    }

    // =========================================================================
    // ValidationException properties
    // =========================================================================

    public function testValidationExceptionCarriesExpectedProperties(): void
    {
        $entry = $this->makeEntry($this->mp4Magic(), error: UPLOAD_ERR_INI_SIZE);
        $v     = new FileValidator('/usr/bin/ffprobe');

        try {
            $v->validate($entry);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
            $this->assertSame('FILE_TOO_LARGE', $e->getErrorCode());
            $this->assertSame(413, $e->getHttpStatus());
            $this->assertNotEmpty($e->getMessage());
        }
    }
}
