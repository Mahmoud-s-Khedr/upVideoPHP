<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Admin;

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile as SlimUploadedFile;
use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

final class VideoAdminControllerTest extends HttpIntegrationTestCase
{
    private ?string $workDir = null;
    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('encoding_jobs', 'videos');

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
        $this->workDir = sys_get_temp_dir() . '/admin_upload_' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0750, true);
        $_ENV['WORK_DIR'] = $this->workDir;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];

        if ($this->workDir !== null) {
            $this->removeDir($this->workDir);
            $this->workDir = null;
            unset($_ENV['WORK_DIR']);
        }

        $this->truncateTables('encoding_jobs', 'videos');
        parent::tearDown();
    }

    public function testGetUploadFormRedirectsWithoutSession(): void
    {
        $_SESSION = [];

        $response = $this->get('/admin/videos/upload');

        $this->assertStatus(302, $response);
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testGetUploadFormReturns200WhenAuthenticated(): void
    {
        $this->logInAdmin();

        $response = $this->get('/admin/videos/upload');

        $this->assertStatus(200, $response);
        $this->assertHtmlResponse($response);
        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        self::assertStringContainsString('Upload Video', $body);
        self::assertStringContainsString('target_qualities[]', $body);
        self::assertStringContainsString('upload-progress-panel', $body);
        self::assertStringContainsString('What Happens Next', $body);
        self::assertStringContainsString('id="quality_1080p" name="target_qualities[]" checked', $body);
        self::assertStringContainsString('id="quality_540p" name="target_qualities[]" checked', $body);
        self::assertStringContainsString('2 selected: 1080p, 540p', $body);
    }

    public function testVideoListIncludesUploadEntryPoint(): void
    {
        $this->logInAdmin();

        $response = $this->get('/admin/videos');

        $this->assertStatus(200, $response);
        $response->getBody()->rewind();
        self::assertStringContainsString('/admin/videos/upload', (string) $response->getBody());
    }

    public function testUploadSubmitWithInvalidCsrfRedirectsBackWithoutDbChanges(): void
    {
        $this->logInAdmin();
        $_SESSION['csrf_token'] = 'good-token';

        $request = $this->uploadRequest(
            '/admin/videos/upload',
            ['_csrf' => 'bad-token'],
            $this->makeUploadedFile($this->tempFile('plain text file'), 'bad.txt', 'text/plain')
        );

        $response = $this->app->handle($request);

        $this->assertStatus(302, $response);
        self::assertSame('/admin/videos/upload', $response->getHeaderLine('Location'));
        $count = (int) (Connection::fetch('SELECT COUNT(*) AS cnt FROM videos')['cnt'] ?? 0);
        self::assertSame(0, $count);
    }

    public function testUploadSubmitWithInvalidFileRedirectsBackWithoutDbChanges(): void
    {
        $this->logInAdmin();

        $request = $this->uploadRequest(
            '/admin/videos/upload',
            ['_csrf' => 'test-csrf'],
            $this->makeUploadedFile($this->tempFile('not a video file'), 'bad.txt', 'text/plain')
        );

        $response = $this->app->handle($request);

        $this->assertStatus(302, $response);
        self::assertSame('/admin/videos/upload', $response->getHeaderLine('Location'));
        $count = (int) (Connection::fetch('SELECT COUNT(*) AS cnt FROM videos')['cnt'] ?? 0);
        self::assertSame(0, $count);
    }

    public function testUploadSubmitWithInvalidFileReturnsJsonForAjaxRequests(): void
    {
        $this->logInAdmin();

        $request = $this->uploadRequest(
            '/admin/videos/upload',
            ['_csrf' => 'test-csrf'],
            $this->makeUploadedFile($this->tempFile('not a video file'), 'bad.txt', 'text/plain'),
            [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]
        );

        $response = $this->app->handle($request);

        $this->assertStatus(422, $response);
        $this->assertJsonResponse($response);
        self::assertSame(
            [
                'error' => 'INVALID_MIME',
                'message' => "MIME type 'text/plain' is not allowed.",
            ],
            $this->json($response)
        );
        $count = (int) (Connection::fetch('SELECT COUNT(*) AS cnt FROM videos')['cnt'] ?? 0);
        self::assertSame(0, $count);
    }

    public function testUploadSubmitQueuesVideoAndRedirectsToDetailWhenNoQualitiesSelected(): void
    {
        if (!is_executable($_ENV['FFPROBE_BIN'] ?? '/usr/bin/ffprobe')) {
            $this->markTestSkipped('ffprobe not available; skipping full admin upload success test.');
        }

        $videoFile = $this->createTinyMp4();
        if ($videoFile === null) {
            $this->markTestSkipped('ffmpeg not available; cannot create real video fixture.');
        }

        $this->logInAdmin();

        $request = $this->uploadRequest(
            '/admin/videos/upload',
            ['_csrf' => 'test-csrf'],
            $this->makeUploadedFile($videoFile, 'sample.mp4', 'video/mp4')
        );

        $response = $this->app->handle($request);

        $this->assertStatus(302, $response);
        self::assertMatchesRegularExpression(
            '#^/admin/videos/[0-9a-f-]{36}$#',
            $response->getHeaderLine('Location')
        );

        $video = Connection::fetch('SELECT * FROM videos ORDER BY id DESC LIMIT 1');
        self::assertNotNull($video);
        self::assertSame('queued', $video['status']);
        self::assertNull($video['target_qualities']);
    }

    public function testUploadSubmitStoresSelectedQualitiesInCanonicalOrder(): void
    {
        if (!is_executable($_ENV['FFPROBE_BIN'] ?? '/usr/bin/ffprobe')) {
            $this->markTestSkipped('ffprobe not available; skipping full admin upload success test.');
        }

        $videoFile = $this->createTinyMp4();
        if ($videoFile === null) {
            $this->markTestSkipped('ffmpeg not available; cannot create real video fixture.');
        }

        $this->logInAdmin();

        $request = $this->uploadRequest(
            '/admin/videos/upload',
            [
                '_csrf' => 'test-csrf',
                'target_qualities' => ['360p', '1080p', '720p'],
            ],
            $this->makeUploadedFile($videoFile, 'qualities.mp4', 'video/mp4')
        );

        $response = $this->app->handle($request);

        $this->assertStatus(302, $response);
        $video = Connection::fetch('SELECT * FROM videos ORDER BY id DESC LIMIT 1');
        self::assertNotNull($video);
        self::assertSame(
            ['1080p', '720p', '360p'],
            json_decode((string) $video['target_qualities'], true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testUploadSubmitReturnsJsonRedirectForAjaxRequests(): void
    {
        if (!is_executable($_ENV['FFPROBE_BIN'] ?? '/usr/bin/ffprobe')) {
            $this->markTestSkipped('ffprobe not available; skipping admin AJAX upload success test.');
        }

        $videoFile = $this->createTinyMp4();
        if ($videoFile === null) {
            $this->markTestSkipped('ffmpeg not available; cannot create real video fixture.');
        }

        $this->logInAdmin();

        $request = $this->uploadRequest(
            '/admin/videos/upload',
            ['_csrf' => 'test-csrf'],
            $this->makeUploadedFile($videoFile, 'sample.mp4', 'video/mp4'),
            [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]
        );

        $response = $this->app->handle($request);

        $this->assertStatus(202, $response);
        $this->assertJsonResponse($response);
        $payload = $this->json($response);

        self::assertSame('queued', $payload['status']);
        self::assertMatchesRegularExpression('#^[0-9a-f-]{36}$#', $payload['video_uuid']);
        self::assertSame('/admin/videos/' . $payload['video_uuid'], $payload['redirect']);
    }

    public function testUploadSubmitQueuesValidMkvWithoutFlashError(): void
    {
        if (!is_executable($_ENV['FFPROBE_BIN'] ?? '/usr/bin/ffprobe')) {
            $this->markTestSkipped('ffprobe not available; skipping MKV admin upload success test.');
        }

        $videoFile = $this->createTinyMkv();
        if ($videoFile === null) {
            $this->markTestSkipped('ffmpeg not available; cannot create real MKV fixture.');
        }

        $this->logInAdmin();

        $request = $this->uploadRequest(
            '/admin/videos/upload',
            ['_csrf' => 'test-csrf'],
            $this->makeUploadedFile($videoFile, 'sample.mkv', 'video/x-matroska')
        );

        $response = $this->app->handle($request);

        $this->assertStatus(302, $response);
        self::assertMatchesRegularExpression(
            '#^/admin/videos/[0-9a-f-]{36}$#',
            $response->getHeaderLine('Location')
        );
        self::assertArrayHasKey('flash', $_SESSION);
        self::assertSame('success', $_SESSION['flash']['type']);
        self::assertStringNotContainsString('matroska,webm', $_SESSION['flash']['message']);

        $video = Connection::fetch('SELECT * FROM videos ORDER BY id DESC LIMIT 1');
        self::assertNotNull($video);
        self::assertSame('queued', $video['status']);
        self::assertSame('sample.mkv', $video['original_name']);

        $job = Connection::fetch(
            'SELECT * FROM encoding_jobs WHERE video_id = :video_id',
            [':video_id' => $video['id']]
        );
        self::assertNotNull($job);
        self::assertSame('queued', $job['status']);
    }

    private function logInAdmin(): void
    {
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_username'] = 'admin';
        $_SESSION['csrf_token'] = 'test-csrf';
    }

    private function uploadRequest(
        string $uri,
        array $body,
        SlimUploadedFile $file,
        array $headers = []
    ): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', $uri)
            ->withParsedBody($body)
            ->withUploadedFiles(['file' => $file]);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function makeUploadedFile(string $path, string $clientFilename, string $mediaType): SlimUploadedFile
    {
        return new SlimUploadedFile(
            $path,
            $clientFilename,
            $mediaType,
            filesize($path),
            UPLOAD_ERR_OK,
            false
        );
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'admin_uc_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function createTinyMp4(): ?string
    {
        $ffmpegBin = $_ENV['FFMPEG_BIN'] ?? '/usr/bin/ffmpeg';
        if (!is_executable($ffmpegBin)) {
            return null;
        }

        $out = tempnam(sys_get_temp_dir(), 'admin_vid_') . '.mp4';
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

    private function createTinyMkv(): ?string
    {
        $ffmpegBin = $_ENV['FFMPEG_BIN'] ?? '/usr/bin/ffmpeg';
        if (!is_executable($ffmpegBin)) {
            return null;
        }

        $out = tempnam(sys_get_temp_dir(), 'admin_vid_') . '.mkv';
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
