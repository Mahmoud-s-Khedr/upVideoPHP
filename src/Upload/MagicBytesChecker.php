<?php

declare(strict_types=1);

namespace VideoSystem\Upload;

/**
 * Binary magic-byte validation for video container formats.
 *
 * Reads the first 16 bytes of a file and checks known container signatures.
 * File extension is never trusted — this operates on raw bytes only.
 */
final class MagicBytesChecker
{
    /**
     * Returns true if the file at $path has a recognised video container signature.
     *
     * Supported containers:
     *   MP4 / MOV / M4V — 'ftyp' box at byte offset 4
     *   MKV / WebM      — EBML header \x1A\x45\xDF\xA3 at offset 0
     *   AVI             — RIFF....AVI  at offset 0
     *   MPEG-TS         — 0x47 sync byte at offset 0 (and 188-byte packet boundary)
     */
    public static function check(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 16);
        fclose($handle);

        if ($header === false || strlen($header) < 4) {
            return false;
        }

        return self::isMp4orMov($header)
            || self::isMkvOrWebm($header)
            || self::isAvi($header)
            || self::isMpegTs($header);
    }

    /**
     * Returns the detected container label, or null if unrecognised.
     */
    public static function detect(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $header = fread($handle, 16);
        fclose($handle);

        if ($header === false || strlen($header) < 4) {
            return null;
        }

        if (self::isMkvOrWebm($header)) {
            // Distinguish MKV from WebM by doctype — not critical for validation; both accepted
            return 'mkv_webm';
        }
        if (self::isMp4orMov($header)) {
            return 'mp4_mov';
        }
        if (self::isAvi($header)) {
            return 'avi';
        }
        if (self::isMpegTs($header)) {
            return 'mpegts';
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private signature checks
    // -------------------------------------------------------------------------

    /**
     * MP4 / MOV / M4V: the four-byte box type 'ftyp' appears at byte offset 4.
     * Some MOV files start with a 'moov' or 'mdat' box before 'ftyp'; we accept those too.
     */
    private static function isMp4orMov(string $header): bool
    {
        if (strlen($header) < 8) {
            return false;
        }

        $boxType = substr($header, 4, 4);

        return in_array($boxType, ['ftyp', 'moov', 'mdat', 'pnot', 'free', 'skip', 'wide'], true);
    }

    /**
     * MKV / WebM: EBML header starts with \x1A\x45\xDF\xA3.
     */
    private static function isMkvOrWebm(string $header): bool
    {
        return str_starts_with($header, "\x1A\x45\xDF\xA3");
    }

    /**
     * AVI: starts with 'RIFF' followed by 4-byte size, then 'AVI '.
     */
    private static function isAvi(string $header): bool
    {
        if (strlen($header) < 12) {
            return false;
        }
        return substr($header, 0, 4) === 'RIFF'
            && substr($header, 8, 4) === 'AVI ';
    }

    /**
     * MPEG-TS: sync byte 0x47 at offset 0.
     * A proper check verifies 0x47 repeats at 188-byte intervals, but for initial
     * header validation the single sync byte is sufficient combined with ffprobe.
     */
    private static function isMpegTs(string $header): bool
    {
        return $header[0] === "\x47";
    }
}
