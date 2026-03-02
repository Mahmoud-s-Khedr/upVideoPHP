<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Upload\MagicBytesChecker;

/**
 * Creates minimal in-memory temp files with the correct header bytes for each
 * container type. No real video files are required.
 */
#[CoversClass(MagicBytesChecker::class)]
final class MagicBytesCheckerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Write bytes to a temp file and return its path. */
    private function writeTempFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'magic_test_');
        assert($path !== false);
        file_put_contents($path, $bytes);
        return $path;
    }

    private function cleanUp(string ...$paths): void
    {
        foreach ($paths as $p) {
            @unlink($p);
        }
    }

    // -------------------------------------------------------------------------
    // check() — recognised formats return true
    // -------------------------------------------------------------------------

    public function testCheckReturnsTrueForMp4(): void
    {
        // 4-byte size field + 'ftyp' at offset 4
        $header = "\x00\x00\x00\x20" . 'ftyp' . 'isom' . "\x00\x00\x02\x00";
        $path   = $this->writeTempFile($header);

        self::assertTrue(MagicBytesChecker::check($path));

        $this->cleanUp($path);
    }

    public function testCheckReturnsTrueForMp4WithMovoBox(): void
    {
        // MOV file starting with 'moov' box instead of 'ftyp'
        $header = "\x00\x00\x00\x08" . 'moov' . str_repeat("\x00", 8);
        $path   = $this->writeTempFile($header);

        self::assertTrue(MagicBytesChecker::check($path));

        $this->cleanUp($path);
    }

    public function testCheckReturnsTrueForMkv(): void
    {
        // EBML header: \x1A\x45\xDF\xA3
        $header = "\x1A\x45\xDF\xA3" . str_repeat("\x00", 12);
        $path   = $this->writeTempFile($header);

        self::assertTrue(MagicBytesChecker::check($path));

        $this->cleanUp($path);
    }

    public function testCheckReturnsTrueForWebM(): void
    {
        // WebM uses the same EBML header as MKV
        $header = "\x1A\x45\xDF\xA3" . str_repeat("\x00", 12);
        $path   = $this->writeTempFile($header);

        self::assertTrue(MagicBytesChecker::check($path));

        $this->cleanUp($path);
    }

    public function testCheckReturnsTrueForAvi(): void
    {
        // AVI: 'RIFF' + 4-byte size + 'AVI '
        $header = 'RIFF' . "\x00\x00\x00\x00" . 'AVI ' . str_repeat("\x00", 4);
        $path   = $this->writeTempFile($header);

        self::assertTrue(MagicBytesChecker::check($path));

        $this->cleanUp($path);
    }

    public function testCheckReturnsTrueForMpegTs(): void
    {
        // MPEG-TS: 0x47 sync byte at offset 0
        $header = "\x47" . str_repeat("\x00", 15);
        $path   = $this->writeTempFile($header);

        self::assertTrue(MagicBytesChecker::check($path));

        $this->cleanUp($path);
    }

    // -------------------------------------------------------------------------
    // check() — unrecognised formats return false
    // -------------------------------------------------------------------------

    public function testCheckReturnsFalseForRandomBytes(): void
    {
        $header = "\xDE\xAD\xBE\xEF" . str_repeat("\xFF", 12);
        $path   = $this->writeTempFile($header);

        self::assertFalse(MagicBytesChecker::check($path));

        $this->cleanUp($path);
    }

    public function testCheckReturnsFalseForTextFile(): void
    {
        $path = $this->writeTempFile('This is a plain text file, not a video.');

        self::assertFalse(MagicBytesChecker::check($path));

        $this->cleanUp($path);
    }

    public function testCheckReturnsFalseForEmptyFile(): void
    {
        $path = $this->writeTempFile('');

        self::assertFalse(MagicBytesChecker::check($path));

        $this->cleanUp($path);
    }

    public function testCheckReturnsFalseForTooShortFile(): void
    {
        // Only 3 bytes — shorter than minimum required header
        $path = $this->writeTempFile("\x00\x01\x02");

        self::assertFalse(MagicBytesChecker::check($path));

        $this->cleanUp($path);
    }

    public function testCheckReturnsFalseForNonExistentPath(): void
    {
        self::assertFalse(MagicBytesChecker::check('/tmp/does_not_exist_ever.bin'));
    }

    // -------------------------------------------------------------------------
    // detect()
    // -------------------------------------------------------------------------

    public function testDetectReturnsMp4MovForMp4(): void
    {
        $header = "\x00\x00\x00\x20" . 'ftyp' . 'isom' . str_repeat("\x00", 8);
        $path   = $this->writeTempFile($header);

        self::assertSame('mp4_mov', MagicBytesChecker::detect($path));

        $this->cleanUp($path);
    }

    public function testDetectReturnsMkvWebmForMkv(): void
    {
        $header = "\x1A\x45\xDF\xA3" . str_repeat("\x00", 12);
        $path   = $this->writeTempFile($header);

        self::assertSame('mkv_webm', MagicBytesChecker::detect($path));

        $this->cleanUp($path);
    }

    public function testDetectReturnsAviForAvi(): void
    {
        $header = 'RIFF' . "\x00\x00\x00\x00" . 'AVI ' . str_repeat("\x00", 4);
        $path   = $this->writeTempFile($header);

        self::assertSame('avi', MagicBytesChecker::detect($path));

        $this->cleanUp($path);
    }

    public function testDetectReturnsMpegTsForTs(): void
    {
        $header = "\x47" . str_repeat("\x00", 15);
        $path   = $this->writeTempFile($header);

        self::assertSame('mpegts', MagicBytesChecker::detect($path));

        $this->cleanUp($path);
    }

    public function testDetectReturnsNullForUnrecognisedFile(): void
    {
        $header = "\xDE\xAD\xBE\xEF" . str_repeat("\xFF", 12);
        $path   = $this->writeTempFile($header);

        self::assertNull(MagicBytesChecker::detect($path));

        $this->cleanUp($path);
    }

    public function testDetectReturnsNullForNonExistentPath(): void
    {
        self::assertNull(MagicBytesChecker::detect('/tmp/absolutely_not_there.bin'));
    }
}
