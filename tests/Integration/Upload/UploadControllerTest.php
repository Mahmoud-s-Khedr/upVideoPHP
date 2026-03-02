<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Upload;

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile as SlimUploadedFile;
use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * Integration tests for POST /api/upload.
 *
 * Auth checks and FileValidator stages 1–3 are exercised without ffprobe.
 * The 202 success path (stages 4–5 + DB insert) is skipped when ffprobe/ffmpeg
 * are absent, so the suite always passes in environments without those tools.
 */
final class UploadControllerTest extends HttpIntegrationTestCase
{
    private const UPLOAD_KEY    = 'test-upload-key-abcdef';
    private const READ_ONLY_KEY = 'test-read-only-key-xyz';

    private string $workDir;
    private array  $tempFiles = [];
    private ServerRequestFactory $rf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->truncateTables('encoding_jobs', 'videos', 'api_keys');

        $this->insertApiKey('uploader', self::UPLOAD_KEY, canUpload: true,  canStream: true);
        $this->insertApiKey('reader',   self::READ_ONLY_KEY, canUpload: false, canStream: true);

        // UploadController creates incoming/<uuid>/ under workDir.
        $this->workDir    = sys_get_temp_dir() . '/uc_test_' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0750, true);
        $_ENV['WORK_DIR'] = $this->workDir;

        $this->rf = new ServerRequestFactory();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
        $this->tempFiles = [];

        $this->removeDir($this->workDir);
        unset($_ENV['WORK_DIR']);

        $this->truncateTables('encoding_jobs', 'videos', 'api_keys');

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Authentication / authorisation
    // -------------------------------------------------------------------------

    public function testNoApiKeyReturns401(): void
    {
        $req = $this->rf->createServerRequest('POST', '/api/upload');
        $res = $this->app->handle($req);

        $this->assertStatus(401, $res);
    }

    public function testApiKeyWithoutUploadPermissionReturns403(): void
    {
        $req = $this->rf->createServerRequest('POST', '/api/upload')
            ->withHeader('Authorization', 'Bearer ' . self::READ_ONLY_KEY);
        $res = $this->app->handle($req);

        $this->assertStatus(403, $res);
        $body = $this->json($res);
        self::assertSame('FORBIDDEN', $body['error']);
    }

    // -------------------------------------------------------------------------
    // Stage 0: missing 'file' field
    // -------------------------------------------------------------------------

    public function testMissingFileFieldReturns422(): void
    {
        $req = $this->rf->createServerRequest('POST', '/api/upload')
            ->withHeader('Authorization', 'Bearer ' . self::UPLOAD_KEY);
        $res = $this->app->handle($req);

        $this->assertStatus(422, $res);
        $body = $this->json($res);
        self::assertSame('MISSING_FILE', $body['error']);
    }

    // -------------------------------------------------------------------------
    // Stage 1: size limit
    // -------------------------------------------------------------------------

    public function testFileLargerThanMaxUploadBytesReturns413(): void
    {
        // Valid MP4 magic bytes, but reported size exceeds the configured maximum.
        $tmpFile  = $this->tempFile("\x00\x00\x00\x18ftypisom" . str_repeat("\x00", 50));
        $maxBytes = (int) ($_ENV['MAX_UPLOAD_BYTES'] ?? 8_589_934_592);
        $uploaded = new SlimUploadedFile($tmpFile, 'big.mp4', 'video/mp4', $maxBytes + 1, UPLOAD_ERR_OK, false);

        $req = $this->rf->createServerRequest('POST', '/api/upload')
            ->withHeader('Authorization', 'Bearer ' . self::UPLOAD_KEY)
            ->withUploadedFiles(['file' => $uploaded]);
        $res = $this->app->handle($req);

        $this->assertStatus(413, $res);
        $body = $this->json($res);
        self::assertSame('FILE_TOO_LARGE', $body['error']);
    }

    /**
     * Slim's UploadedFile::moveTo() does NOT inspect the error code, so it moves
     * the temp file without issue. FileValidator then sees UPLOAD_ERR_FORM_SIZE
     * and returns 413.
     */
    public function testPhpUploadErrFormSizeReturns413(): void
    {
        $tmpFile  = $this->tempFile('tiny payload');
        $uploaded = new SlimUploadedFile($tmpFile, 'x.mp4', 'video/mp4', 12, UPLOAD_ERR_FORM_SIZE, false);

        $req = $this->rf->createServerRequest('POST', '/api/upload')
            ->withHeader('Authorization', 'Bearer ' . self::UPLOAD_KEY)
            ->withUploadedFiles(['file' => $uploaded]);
        $res = $this->app->handle($req);

        $this->assertStatus(413, $res);
        $body = $this->json($res);
        self::assertSame('FILE_TOO_LARGE', $body['error']);
    }

    // -------------------------------------------------------------------------
    // Stage 2: MIME allowlist
    // -------------------------------------------------------------------------

    public function testDisallowedMimeTextPlainReturns422(): void
    {
        $tmpFile  = $this->tempFile('not a video file at all');
        $size     = filesize($tmpFile);
        $uploaded = new SlimUploadedFile($tmpFile, 'test.txt', 'text/plain', $size, UPLOAD_ERR_OK, false);

        $req = $this->rf->createServerRequest('POST', '/api/upload')
            ->withHeader('Authorization', 'Bearer ' . self::UPLOAD_KEY)
            ->withUploadedFiles(['file' => $uploaded]);
        $res = $this->app->handle($req);

        $this->assertStatus(422, $res);
        $body = $this->json($res);
        self::assertSame('INVALID_MIME', $body['error']);
    }

    public function testDisallowedMimeImageJpegReturns422(): void
    {
        $tmpFile  = $this->tempFile("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 20));
        $size     = filesize($tmpFile);
        $uploaded = new SlimUploadedFile($tmpFile, 'photo.jpg', 'image/jpeg', $size, UPLOAD_ERR_OK, false);

        $req = $this->rf->createServerRequest('POST', '/api/upload')
            ->withHeader('Authorization', 'Bearer ' . self::UPLOAD_KEY)
            ->withUploadedFiles(['file' => $uploaded]);
        $res = $this->app->handle($req);

        $this->assertStatus(422, $res);
        $body = $this->json($res);
        self::assertSame('INVALID_MIME', $body['error']);
    }

    // -------------------------------------------------------------------------
    // Stage 3: magic byte validation
    // -------------------------------------------------------------------------

    public function testMp4MimeButNoFtypBoxReturns422(): void
    {
        // Declares video/mp4 but has no 'ftyp' box at byte offset 4.
        $tmpFile  = $this->tempFile('this is definitely not a video file!!!!');
        $size     = filesize($tmpFile);
        $uploaded = new SlimUploadedFile($tmpFile, 'fake.mp4', 'video/mp4', $size, UPLOAD_ERR_OK, false);

        $req = $this->rf->createServerRequest('POST', '/api/upload')
            ->withHeader('Authorization', 'Bearer ' . self::UPLOAD_KEY)
            ->withUploadedFiles(['file' => $uploaded]);
        $res = $this->app->handle($req);

        $this->assertStatus(422, $res);
        $body = $this->json($res);
        self::assertSame('INVALID_FILE_MAGIC', $body['error']);
    }

    public function testMkvMimeButNotEbmlHeaderReturns422(): void
    {
        // Declares video/x-matroska but bytes are zero padding — not an EBML header.
        $tmpFile  = $this->tempFile(str_repeat("\x00", 32));
        $size     = filesize($tmpFile);
        $uploaded = new SlimUploadedFile($tmpFile, 'fake.mkv', 'video/x-matroska', $size, UPLOAD_ERR_OK, false);

        $req = $this->rf->createServerRequest('POST', '/api/upload')
            ->withHeader('Authorization', 'Bearer ' . self::UPLOAD_KEY)
            ->withUploadedFiles(['file' => $uploaded]);
        $res = $this->app->handle($req);

        $this->assertStatus(422, $res);
        $body = $this->json($res);
        self::assertSame('INVALID_FILE_MAGIC', $body['error']);
    }

    // -------------------------------------------------------------------------
    // Success path — requires a real ffprobe + ffmpeg install
    // -------------------------------------------------------------------------

    public function testValidVideoReturns202WithQueuedStatus(): void
    {
        if (!is_executable($_ENV['FFPROBE_BIN'] ?? '/usr/bin/ffprobe')) {
            $this->markTestSkipped('ffprobe not available; skipping full upload success test.');
        }

        $videoFile = $this->createTinyMp4();
        if ($videoFile === null) {
            $this->markTestSkipped('ffmpeg not available; cannot create real video fixture.');
        }

        $size     = filesize($videoFile);
        $uploaded = new SlimUploadedFile($videoFile, 'sample.mp4', 'video/mp4', $size, UPLOAD_ERR_OK, false);

        $req = $this->rf->createServerRequest('POST', '/api/upload')
            ->withHeader('Authorization', 'Bearer ' . self::UPLOAD_KEY)
            ->withUploadedFiles(['file' => $uploaded]);
        $res = $this->app->handle($req);

        $this->assertStatus(202, $res);

        $body = $this->json($res);
        self::assertArrayHasKey('video_uuid', $body);
        self::assertSame('queued', $body['status']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $body['video_uuid'],
        );

        // Verify both DB rows exist.
        $pdo  = Connection::get();
        $uuid = $body['video_uuid'];

        $vid = $pdo->query(
            'SELECT * FROM videos WHERE uuid = ' . $pdo->quote($uuid)
        )->fetch(\PDO::FETCH_ASSOC);

        self::assertNotFalse($vid, 'videos row should exist');
        self::assertSame('queued', $vid['status']);
        self::assertSame('sample.mp4', $vid['original_name']);

        $jobs = $pdo->query(
            "SELECT * FROM encoding_jobs WHERE video_id = {$vid['id']}"
        )->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(1, $jobs, 'Exactly one encoding_jobs row expected');
        self::assertSame('queued', $jobs[0]['status']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'uc_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * Generate a tiny but real MP4 using ffmpeg's lavfi testsrc.
     * Returns the path on success, null if ffmpeg is absent.
     */
    private function createTinyMp4(): ?string
    {
        $ffmpegBin = $_ENV['FFMPEG_BIN'] ?? '/usr/bin/ffmpeg';
        if (!is_executable($ffmpegBin)) {
            return null;
        }

        $out = tempnam(sys_get_temp_dir(), 'uc_vid_') . '.mp4';
        $cmd = sprintf(
            '%s -f lavfi -i testsrc=duration=0.1:size=32x32:rate=1'
            . ' -vcodec libx264 -preset ultrafast -tune stillimage -an -y %s 2>/dev/null',
            escapeshellarg($ffmpegBin),
            escapeshellarg($out),
        );

        exec($cmd, $_, $code);

        if ($code !== 0 || !file_exists($out) || filesize($out) === 0) {
            @unlink($out);
            return null;
        }

        $this->tempFiles[] = $out;
        return $out;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
