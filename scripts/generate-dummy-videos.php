#!/usr/bin/env php
<?php

/**
 * generate-dummy-videos.php
 *
 * Generates synthetic test videos (FFmpeg lavfi testsrc2) with 3 audio tracks
 * (English/Spanish/French) and 3 subtitle tracks, then uploads each through the
 * real API upload flow.
 *
 * Usage:
 *   php scripts/generate-dummy-videos.php [options]
 *
 * Options:
 *   --count=N          Number of videos to generate (default: 3)
 *   --duration=S       Duration of each video in seconds (default: 30)
 *   --qualities=a,b    Comma-separated rendition labels (default: 720p,480p)
 *                      Allowed: 1080p, 720p, 540p, 480p, 360p
 *   --api-key=KEY      Bearer token for API auth (or set TEST_API_KEY env var)
 *   --base-url=URL     API base URL (or set APP_BASE_URL env var)
 *   --wait             Poll progress until each video reaches status=ready
 *   --timeout=S        Max seconds to wait per video when --wait is set (default: 600)
 *   --help             Show this help text
 *
 * Creating a test API key (run once):
 *   mysql -u root videosystem -e "
 *     INSERT INTO api_keys (name, key_hash, can_upload, can_stream)
 *     VALUES ('test-seeder', SHA2('my-test-key-here', 256), 1, 1);"
 *
 *   Then pass: --api-key=my-test-key-here
 *   Note: The API uses bcrypt internally — use the /api/admin flow or the
 *   insertApiKey() test helper to create a properly hashed key.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap: load .env from project root
// ---------------------------------------------------------------------------

$projectRoot = dirname(__DIR__);

function loadEnvFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && !isset($_ENV[$key])) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

loadEnvFile($projectRoot . '/.env');

// ---------------------------------------------------------------------------
// Parse CLI arguments
// ---------------------------------------------------------------------------

$opts = getopt('', [
    'count:',
    'duration:',
    'qualities:',
    'api-key:',
    'base-url:',
    'wait',
    'timeout:',
    'help',
]);

if (isset($opts['help'])) {
    $src = file_get_contents(__FILE__);
    preg_match('/\/\*\*(.*?)\*\//s', $src, $m);
    echo trim(preg_replace('/^\s*\*\s?/m', '', $m[1] ?? '')) . "\n";
    exit(0);
}

$count     = (int) ($opts['count']    ?? 3);
$duration  = (int) ($opts['duration'] ?? 30);
$apiKey    = (string) ($opts['api-key']  ?? $_ENV['TEST_API_KEY'] ?? '');
$baseUrl   = rtrim((string) ($opts['base-url'] ?? $_ENV['APP_BASE_URL'] ?? ''), '/');
$doWait    = isset($opts['wait']);
$waitTimeout = (int) ($opts['timeout'] ?? 600);
$rawQualities = isset($opts['qualities'])
    ? array_filter(array_map('trim', explode(',', (string) $opts['qualities'])))
    : ['720p', '480p'];

$allowedQualities = ['1080p', '720p', '540p', '480p', '360p'];
$qualities = array_values(array_filter($rawQualities, fn($q) => in_array($q, $allowedQualities, true)));
if (empty($qualities)) {
    err("No valid qualities specified. Allowed: " . implode(', ', $allowedQualities));
    exit(1);
}

if ($count < 1 || $count > 50) {
    err("--count must be between 1 and 50.");
    exit(1);
}
if ($duration < 5 || $duration > 3600) {
    err("--duration must be between 5 and 3600 seconds.");
    exit(1);
}
if ($apiKey === '') {
    err("API key required. Use --api-key=<key> or set TEST_API_KEY env var.");
    exit(1);
}
if ($baseUrl === '') {
    err("Base URL required. Use --base-url=<url> or set APP_BASE_URL env var.");
    exit(1);
}

// ---------------------------------------------------------------------------
// Resolve binaries
// ---------------------------------------------------------------------------

$ffmpegBin  = $_ENV['FFMPEG_BIN']  ?? 'ffmpeg';
$ffprobeBin = $_ENV['FFPROBE_BIN'] ?? 'ffprobe';

foreach ([$ffmpegBin, $ffprobeBin] as $bin) {
    exec("which " . escapeshellarg($bin) . " 2>/dev/null", $out, $rc);
    if ($rc !== 0) {
        // Try just the binary name without full path check
        exec($bin . " -version 2>/dev/null", $out2, $rc2);
        if ($rc2 !== 0) {
            err("Binary not found: {$bin}. Set FFMPEG_BIN / FFPROBE_BIN in .env.");
            exit(1);
        }
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Print to stderr */
function err(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
}

/** Print to stdout */
function out(string $msg): void
{
    echo $msg . "\n";
}

/** Format bytes to human-readable string */
function fmtBytes(int $bytes): string
{
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1024 / 1024, 1) . ' MB';
}

/** Format elapsed seconds */
function fmtSec(float $s): string
{
    return round($s, 1) . 's';
}

/**
 * Make an HTTP request using curl.
 * @return array{status: int, body: string}
 */
function httpRequest(string $method, string $url, ?string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    $responseBody = curl_exec($ch);
    $status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error        = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException("curl error: {$error}");
    }

    return ['status' => $status, 'body' => (string) $responseBody];
}

/**
 * Upload a file to a presigned PUT URL using streaming curl (memory-efficient).
 * @return array{status: int}
 */
function putFileToUrl(string $url, string $filePath, string $contentType): array
{
    $fileSize = filesize($filePath);
    $fh       = fopen($filePath, 'rb');
    if ($fh === false) {
        throw new RuntimeException("Cannot open file: {$filePath}");
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_INFILE, $fh);
    curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: {$contentType}",
        "Content-Length: {$fileSize}",
    ]);

    $responseBody = curl_exec($ch);
    $status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error        = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($responseBody === false) {
        throw new RuntimeException("curl PUT error: {$error}");
    }

    return ['status' => $status];
}

// ---------------------------------------------------------------------------
// SRT subtitle generation
// ---------------------------------------------------------------------------

/**
 * Generate an SRT subtitle file with one cue every 5 seconds.
 * Each cue contains a language-specific prefix for easy identification.
 */
function generateSrtFile(string $path, int $duration, string $langLabel): void
{
    $interval = 5; // seconds per cue
    $cues     = [];
    $index    = 1;

    for ($start = 0; $start < $duration; $start += $interval) {
        $end = min($start + $interval, $duration);

        $startMs = $start * 1000;
        $endMs   = $end * 1000;

        $startStr = sprintf(
            '%02d:%02d:%02d,%03d',
            intdiv($startMs, 3600000),
            intdiv($startMs % 3600000, 60000),
            intdiv($startMs % 60000, 1000),
            $startMs % 1000
        );
        $endStr = sprintf(
            '%02d:%02d:%02d,%03d',
            intdiv($endMs, 3600000),
            intdiv($endMs % 3600000, 60000),
            intdiv($endMs % 60000, 1000),
            $endMs % 1000
        );

        $lineNum  = (int) ($start / $interval) + 1;
        $cues[]   = "{$index}\n{$startStr} --> {$endStr}\n[{$langLabel}] Subtitle line {$lineNum}";
        $index++;
    }

    file_put_contents($path, implode("\n\n", $cues) . "\n");
}

// ---------------------------------------------------------------------------
// Video generation
// ---------------------------------------------------------------------------

/** Resolutions cycled through for each video index (0-based). */
const RESOLUTIONS = [
    ['width' => 1280, 'height' => 720],
    ['width' => 854,  'height' => 480],
    ['width' => 640,  'height' => 360],
];

/**
 * Generate a synthetic MP4 with 3 audio tracks and 3 embedded subtitle tracks.
 * Returns the path to the generated file.
 */
function generateVideo(
    string $ffmpegBin,
    int $duration,
    int $width,
    int $height,
    string $tmpDir
): string {
    $uid    = bin2hex(random_bytes(8));
    $outMp4 = "{$tmpDir}/dummy_{$uid}.mp4";

    // Write 3 SRT subtitle files
    $srtFiles = [
        'eng' => "{$tmpDir}/dummy_{$uid}_en.srt",
        'spa' => "{$tmpDir}/dummy_{$uid}_es.srt",
        'fra' => "{$tmpDir}/dummy_{$uid}_fr.srt",
    ];

    generateSrtFile($srtFiles['eng'], $duration, 'English');
    generateSrtFile($srtFiles['spa'], $duration, 'Spanish');
    generateSrtFile($srtFiles['fra'], $duration, 'French');

    // Build FFmpeg command
    // Inputs:
    //   0: video (testsrc2)
    //   1: audio English  (440 Hz sine)
    //   2: audio Spanish  (880 Hz sine)
    //   3: audio French   (660 Hz sine)
    //   4: subtitles English
    //   5: subtitles Spanish
    //   6: subtitles French
    $cmd = implode(' ', [
        escapeshellarg($ffmpegBin),
        '-f lavfi -i', escapeshellarg("testsrc2=duration={$duration}:size={$width}x{$height}:rate=30"),
        '-f lavfi -i', escapeshellarg("sine=frequency=440:duration={$duration}"),
        '-f lavfi -i', escapeshellarg("sine=frequency=880:duration={$duration}"),
        '-f lavfi -i', escapeshellarg("sine=frequency=660:duration={$duration}"),
        '-i', escapeshellarg($srtFiles['eng']),
        '-i', escapeshellarg($srtFiles['spa']),
        '-i', escapeshellarg($srtFiles['fra']),
        '-map 0:v -map 1:a -map 2:a -map 3:a -map 4:s -map 5:s -map 6:s',
        '-c:v libx264 -preset ultrafast -crf 28',
        '-c:a:0 aac -b:a 96k',
        '-metadata:s:a:0 language=eng', '-metadata:s:a:0 title=English',
        '-c:a:1 aac -b:a 96k',
        '-metadata:s:a:1 language=spa', '-metadata:s:a:1 title=Spanish',
        '-c:a:2 aac -b:a 96k',
        '-metadata:s:a:2 language=fra', '-metadata:s:a:2 title=French',
        '-c:s:0 mov_text',
        '-metadata:s:s:0 language=eng', '-metadata:s:s:0 title=English',
        '-c:s:1 mov_text',
        '-metadata:s:s:1 language=spa', '-metadata:s:s:1 title=Spanish',
        '-c:s:2 mov_text',
        '-metadata:s:s:2 language=fra', '-metadata:s:s:2 title=French',
        '-movflags +faststart',
        '-y',
        escapeshellarg($outMp4),
        '2>/dev/null',
    ]);

    exec($cmd, $cmdOut, $rc);

    if ($rc !== 0 || !is_file($outMp4)) {
        // Retry without subtitle encoding if mov_text fails (codec availability varies)
        $cmdNoSubs = implode(' ', [
            escapeshellarg($ffmpegBin),
            '-f lavfi -i', escapeshellarg("testsrc2=duration={$duration}:size={$width}x{$height}:rate=30"),
            '-f lavfi -i', escapeshellarg("sine=frequency=440:duration={$duration}"),
            '-f lavfi -i', escapeshellarg("sine=frequency=880:duration={$duration}"),
            '-f lavfi -i', escapeshellarg("sine=frequency=660:duration={$duration}"),
            '-i', escapeshellarg($srtFiles['eng']),
            '-i', escapeshellarg($srtFiles['spa']),
            '-i', escapeshellarg($srtFiles['fra']),
            '-map 0:v -map 1:a -map 2:a -map 3:a -map 4:s -map 5:s -map 6:s',
            '-c:v libx264 -preset ultrafast -crf 28',
            '-c:a:0 aac -b:a 96k',
            '-metadata:s:a:0 language=eng', '-metadata:s:a:0 title=English',
            '-c:a:1 aac -b:a 96k',
            '-metadata:s:a:1 language=spa', '-metadata:s:a:1 title=Spanish',
            '-c:a:2 aac -b:a 96k',
            '-metadata:s:a:2 language=fra', '-metadata:s:a:2 title=French',
            '-c:s copy',
            '-metadata:s:s:0 language=eng', '-metadata:s:s:0 title=English',
            '-metadata:s:s:1 language=spa', '-metadata:s:s:1 title=Spanish',
            '-metadata:s:s:2 language=fra', '-metadata:s:s:2 title=French',
            '-movflags +faststart',
            '-y',
            escapeshellarg($outMp4),
            '2>/dev/null',
        ]);
        exec($cmdNoSubs, $cmdOut2, $rc2);

        if ($rc2 !== 0 || !is_file($outMp4)) {
            // Final fallback: no subtitles at all
            $cmdFallback = implode(' ', [
                escapeshellarg($ffmpegBin),
                '-f lavfi -i', escapeshellarg("testsrc2=duration={$duration}:size={$width}x{$height}:rate=30"),
                '-f lavfi -i', escapeshellarg("sine=frequency=440:duration={$duration}"),
                '-f lavfi -i', escapeshellarg("sine=frequency=880:duration={$duration}"),
                '-f lavfi -i', escapeshellarg("sine=frequency=660:duration={$duration}"),
                '-map 0:v -map 1:a -map 2:a -map 3:a',
                '-c:v libx264 -preset ultrafast -crf 28',
                '-c:a:0 aac -b:a 96k',
                '-metadata:s:a:0 language=eng', '-metadata:s:a:0 title=English',
                '-c:a:1 aac -b:a 96k',
                '-metadata:s:a:1 language=spa', '-metadata:s:a:1 title=Spanish',
                '-c:a:2 aac -b:a 96k',
                '-metadata:s:a:2 language=fra', '-metadata:s:a:2 title=French',
                '-movflags +faststart',
                '-y',
                escapeshellarg($outMp4),
                '2>/dev/null',
            ]);
            exec($cmdFallback, $cmdOut3, $rc3);

            if ($rc3 !== 0 || !is_file($outMp4)) {
                throw new RuntimeException("FFmpeg failed to generate video (exit code: {$rc3})");
            }
        }
    }

    // Clean up temp SRT files
    foreach ($srtFiles as $f) {
        @unlink($f);
    }

    return $outMp4;
}

// ---------------------------------------------------------------------------
// API helpers
// ---------------------------------------------------------------------------

function apiPost(string $baseUrl, string $path, array $payload, string $apiKey): array
{
    $url     = $baseUrl . $path;
    $body    = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        "Authorization: Bearer {$apiKey}",
    ];

    $resp = httpRequest('POST', $url, $body, $headers);
    $data = json_decode($resp['body'], true) ?? [];
    return ['status' => $resp['status'], 'data' => $data];
}

function apiGet(string $baseUrl, string $path, string $apiKey): array
{
    $url     = $baseUrl . $path;
    $headers = [
        'Accept: application/json',
        "Authorization: Bearer {$apiKey}",
    ];

    $resp = httpRequest('GET', $url, null, $headers);
    $data = json_decode($resp['body'], true) ?? [];
    return ['status' => $resp['status'], 'data' => $data];
}

// ---------------------------------------------------------------------------
// Progress polling
// ---------------------------------------------------------------------------

function pollProgress(
    string $baseUrl,
    string $uuid,
    string $apiKey,
    int $timeoutSec
): string {
    $deadline = time() + $timeoutSec;
    $lastPct  = -1;
    $lastStage = '';

    while (time() < $deadline) {
        $resp   = apiGet($baseUrl, "/api/videos/{$uuid}/progress", $apiKey);
        $data   = $resp['data'];
        $status = $data['status'] ?? 'unknown';
        $pct    = (int) ($data['progress_pct'] ?? 0);
        $stage  = (string) ($data['current_stage'] ?? '');
        $rend   = (string) ($data['current_rendition'] ?? '');

        if ($pct !== $lastPct || $stage !== $lastStage) {
            $detail = $rend !== '' ? " [{$rend}]" : '';
            $detail .= $stage !== '' ? " {$stage}" : '';
            fwrite(STDERR, "\r  Progress: {$pct}%{$detail}          ");
            $lastPct   = $pct;
            $lastStage = $stage;
        }

        if ($status === 'ready' || $status === 'error') {
            fwrite(STDERR, "\n");
            return $status;
        }

        sleep(3);
    }

    fwrite(STDERR, "\n");
    return 'timeout';
}

// ---------------------------------------------------------------------------
// Main loop
// ---------------------------------------------------------------------------

$tmpDir   = sys_get_temp_dir();
$results  = [];

out("Generating {$count} dummy video(s) ({$duration}s each, qualities: " . implode(',', $qualities) . ")");
out(str_repeat('-', 70));

for ($i = 1; $i <= $count; $i++) {
    $res      = RESOLUTIONS[($i - 1) % count(RESOLUTIONS)];
    $width    = $res['width'];
    $height   = $res['height'];
    $filename = "dummy-test-{$width}x{$height}-{$i}.mp4";

    out("\n[{$i}/{$count}] Resolution: {$width}x{$height}  File: {$filename}");

    // ---- Step 1: Generate video file ----
    $t0 = microtime(true);
    fwrite(STDERR, "  Generating video...");

    $videoPath = null;
    try {
        $videoPath = generateVideo($ffmpegBin, $duration, $width, $height, $tmpDir);
    } catch (Throwable $e) {
        fwrite(STDERR, " FAILED\n");
        err("  Error: " . $e->getMessage());
        $results[] = ['index' => $i, 'uuid' => null, 'status' => 'gen_failed', 'elapsed' => 0.0];
        continue;
    }

    $sizeBytes = filesize($videoPath);
    $genTime   = microtime(true) - $t0;
    fwrite(STDERR, " done (" . fmtSec($genTime) . ", " . fmtBytes($sizeBytes) . ")\n");

    // ---- Step 2: POST /api/upload/init ----
    fwrite(STDERR, "  Calling /api/upload/init...");
    $t1 = microtime(true);

    $initResp = apiPost($baseUrl, '/api/upload/init', [
        'filename'         => $filename,
        'size_bytes'       => $sizeBytes,
        'content_type'     => 'video/mp4',
        'target_qualities' => $qualities,
    ], $apiKey);

    if ($initResp['status'] !== 201) {
        fwrite(STDERR, " FAILED (HTTP {$initResp['status']})\n");
        err("  Response: " . json_encode($initResp['data']));
        @unlink($videoPath);
        $results[] = ['index' => $i, 'uuid' => null, 'status' => 'init_failed', 'elapsed' => 0.0];
        continue;
    }

    $uuid       = $initResp['data']['video_uuid'] ?? '';
    $uploadUrl  = $initResp['data']['upload_url']  ?? '';
    $uploadMode = $initResp['data']['upload_mode'] ?? 'single';

    if ($uuid === '' || $uploadUrl === '') {
        fwrite(STDERR, " FAILED (missing uuid or upload_url in response)\n");
        @unlink($videoPath);
        $results[] = ['index' => $i, 'uuid' => null, 'status' => 'init_failed', 'elapsed' => 0.0];
        continue;
    }

    fwrite(STDERR, " done  uuid={$uuid}\n");

    if ($uploadMode !== 'single') {
        fwrite(STDERR, "  Warning: multipart mode not supported by this script. Skipping.\n");
        @unlink($videoPath);
        $results[] = ['index' => $i, 'uuid' => $uuid, 'status' => 'skipped_multipart', 'elapsed' => 0.0];
        continue;
    }

    // ---- Step 3: PUT file to presigned B2 URL ----
    fwrite(STDERR, "  Uploading to B2...");
    $t2 = microtime(true);

    try {
        $putResp = putFileToUrl($uploadUrl, $videoPath, 'video/mp4');
    } catch (Throwable $e) {
        fwrite(STDERR, " FAILED\n");
        err("  Error: " . $e->getMessage());
        @unlink($videoPath);
        $results[] = ['index' => $i, 'uuid' => $uuid, 'status' => 'upload_failed', 'elapsed' => 0.0];
        continue;
    }

    @unlink($videoPath); // clean up local file immediately

    // B2 presigned PUT returns 200 on success
    if ($putResp['status'] < 200 || $putResp['status'] >= 300) {
        fwrite(STDERR, " FAILED (HTTP {$putResp['status']})\n");
        $results[] = ['index' => $i, 'uuid' => $uuid, 'status' => 'upload_failed', 'elapsed' => 0.0];
        continue;
    }

    $uploadTime = microtime(true) - $t2;
    fwrite(STDERR, " done (" . fmtSec($uploadTime) . ")\n");

    // ---- Step 4: POST /api/upload/complete ----
    fwrite(STDERR, "  Completing upload...");

    $completeResp = apiPost($baseUrl, '/api/upload/complete', [
        'video_uuid' => $uuid,
    ], $apiKey);

    if ($completeResp['status'] !== 202) {
        fwrite(STDERR, " FAILED (HTTP {$completeResp['status']})\n");
        err("  Response: " . json_encode($completeResp['data']));
        $results[] = ['index' => $i, 'uuid' => $uuid, 'status' => 'complete_failed', 'elapsed' => 0.0];
        continue;
    }

    $totalTime = microtime(true) - $t0;
    fwrite(STDERR, " done — queued for encoding\n");
    out("  uuid: {$uuid}");

    // ---- Step 5 (optional): poll progress ----
    $finalStatus = 'queued';
    if ($doWait) {
        fwrite(STDERR, "  Waiting for encoding to complete (timeout: {$waitTimeout}s)...\n");
        $finalStatus = pollProgress($baseUrl, $uuid, $apiKey, $waitTimeout);
        out("  Final status: {$finalStatus}");
    }

    $results[] = [
        'index'   => $i,
        'uuid'    => $uuid,
        'status'  => $finalStatus,
        'elapsed' => round($totalTime, 1),
    ];
}

// ---------------------------------------------------------------------------
// Summary table
// ---------------------------------------------------------------------------

out("\n" . str_repeat('-', 70));
out("Summary:");
out(sprintf("  %-4s  %-38s  %-16s  %s", '#', 'UUID', 'Status', 'Time'));
out(sprintf("  %-4s  %-38s  %-16s  %s", str_repeat('-', 4), str_repeat('-', 38), str_repeat('-', 16), str_repeat('-', 6)));

foreach ($results as $r) {
    out(sprintf(
        "  %-4s  %-38s  %-16s  %s",
        $r['index'],
        $r['uuid'] ?? '(none)',
        $r['status'],
        $r['elapsed'] > 0 ? fmtSec($r['elapsed']) : '-'
    ));
}

$queued  = count(array_filter($results, fn($r) => $r['status'] === 'queued'));
$ready   = count(array_filter($results, fn($r) => $r['status'] === 'ready'));
$failed  = count(array_filter($results, fn($r) => !in_array($r['status'], ['queued', 'ready'], true)));

out("\nTotal: {$count}  Queued: {$queued}  Ready: {$ready}  Failed/Skipped: {$failed}");
