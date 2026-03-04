<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Player\EmbedOriginService;
use VideoSystem\Player\EmbedSettingsLoader;
use VideoSystem\Storage\B2Client;

/**
 * Admin CRUD for global and per-video embed settings.
 *
 * GET  /admin/embed-settings              — Global settings form
 * POST /admin/embed-settings              — Save global settings
 * GET  /admin/videos/{uuid}/embed         — Per-video embed settings + embed code
 * POST /admin/videos/{uuid}/embed         — Save per-video override
 */
final class EmbedSettingsController
{
    private const LOGO_MAX_UPLOAD_BYTES = 2_097_152;
    private const GLOBAL_LOGO_TTL_SECONDS = 900;

    public function globalForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rawSettings = Connection::fetch(
            'SELECT * FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1'
        );
        $normalized = $this->loader()->normalize($rawSettings ?? []);
        $rawLogoUrl = $this->sanitizeNullableHttpUrl($rawSettings['logo_url'] ?? '');
        $uploadedLogoUrl = $normalized['logo_upload_b2_key'] !== null ? Config::appBaseUrl() . '/branding/logo/global' : null;

        $twig = TwigFactory::create();
        $html = $twig->render('embed-settings.twig', [
            'settings' => $normalized,
            'raw_logo_url' => $rawLogoUrl,
            'has_uploaded_logo' => $normalized['logo_upload_b2_key'] !== null,
            'uploaded_logo_name' => $normalized['logo_upload_original_name'],
            'uploaded_logo_preview_url' => $uploadedLogoUrl,
            'effective_logo_preview_url' => $uploadedLogoUrl ?? $rawLogoUrl,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function globalSave(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        if (!TwigFactory::validateCsrf($body['_csrf'] ?? '')) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $this->redirect($response, '/admin/embed-settings');
        }

        $files = $request->getUploadedFiles();
        $logoUpload = $files['logo_upload'] ?? null;
        $existing = Connection::fetch('SELECT * FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $settings = $this->buildGlobalSettingsPayload($body);

        try {
            $settings = array_merge($settings, $this->resolveLogoUploadPayload(
                $logoUpload,
                $existing ?? [],
                isset($body['remove_uploaded_logo'])
            ));
        } catch (\RuntimeException $e) {
            TwigFactory::flash('error', $e->getMessage());
            return $this->redirect($response, '/admin/embed-settings');
        }

        if ($existing !== null) {
            Connection::execute(
                'UPDATE embed_settings SET
                    accent_color               = :accent_color,
                    logo_url                   = :logo_url,
                    logo_upload_b2_key         = :logo_upload_b2_key,
                    logo_upload_original_name  = :logo_upload_original_name,
                    logo_position              = :logo_position,
                    title_visible              = :title_visible,
                    force_disable_adblock      = :force_disable_adblock,
                    preroll_url                = :preroll_url,
                    preroll_skip_after         = :preroll_skip_after,
                    preroll_click_url          = :preroll_click_url,
                    preroll_source_kind        = :preroll_source_kind,
                    postroll_url               = :postroll_url,
                    postroll_skip_after        = :postroll_skip_after,
                    postroll_click_url         = :postroll_click_url,
                    postroll_source_kind       = :postroll_source_kind,
                    midroll_cues               = :midroll_cues,
                    watch_top_banner_html      = :watch_top_banner_html,
                    watch_bottom_banner_html   = :watch_bottom_banner_html,
                    embed_banner_html          = :embed_banner_html,
                    general_script_url         = :general_script_url,
                    general_html_code          = :general_html_code,
                    direct_play_url            = :direct_play_url,
                    direct_play_mode           = :direct_play_mode,
                    direct_popup_bypass_iframe = :direct_popup_bypass_iframe,
                    allowed_embed_origins      = :allowed_embed_origins
                 WHERE video_id IS NULL',
                $settings
            );
        } else {
            Connection::execute(
                'INSERT INTO embed_settings
                    (video_id, accent_color, logo_url, logo_upload_b2_key, logo_upload_original_name, logo_position, title_visible, force_disable_adblock,
                     preroll_url, preroll_skip_after, preroll_click_url, preroll_source_kind,
                     postroll_url, postroll_skip_after, postroll_click_url, postroll_source_kind, midroll_cues,
                     watch_top_banner_html, watch_bottom_banner_html, embed_banner_html,
                     general_script_url, general_html_code,
                     direct_play_url, direct_play_mode, direct_popup_bypass_iframe,
                     allowed_embed_origins)
                 VALUES
                    (NULL, :accent_color, :logo_url, :logo_upload_b2_key, :logo_upload_original_name, :logo_position, :title_visible, :force_disable_adblock,
                     :preroll_url, :preroll_skip_after, :preroll_click_url, :preroll_source_kind,
                     :postroll_url, :postroll_skip_after, :postroll_click_url, :postroll_source_kind, :midroll_cues,
                     :watch_top_banner_html, :watch_bottom_banner_html, :embed_banner_html,
                     :general_script_url, :general_html_code,
                     :direct_play_url, :direct_play_mode, :direct_popup_bypass_iframe,
                     :allowed_embed_origins)',
                $settings
            );
        }

        TwigFactory::flash('success', 'Global embed settings saved.');
        return $this->redirect($response, '/admin/embed-settings');
    }

    public function globalLogo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $settings = Connection::fetch(
            'SELECT logo_upload_b2_key FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1'
        );
        $key = is_array($settings) ? $this->normalizeNullableString($settings['logo_upload_b2_key'] ?? null) : null;

        if ($key === null || !B2Client::exists($key)) {
            return $response->withStatus(404);
        }

        $url = B2Client::presignUrl($key, self::GLOBAL_LOGO_TTL_SECONDS);
        return $response
            ->withStatus(302)
            ->withHeader('Location', $url)
            ->withHeader('Cache-Control', 'no-store');
    }

    public function videoForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid = $request->getAttribute('uuid');

        $video = Connection::fetch(
            'SELECT id, uuid, original_name, status FROM videos WHERE uuid = :uuid',
            [':uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $this->redirect($response, '/admin/videos');
        }

        $params = $request->getQueryParams();
        if (isset($params['delete_override'])) {
            TwigFactory::flash('error', 'Please use the form button to remove the override.');
            return $this->redirect($response, '/admin/videos/' . $uuid . '/embed');
        }

        $loader = $this->loader();
        $globalRaw = Connection::fetch('SELECT * FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');
        $overrideRaw = Connection::fetch(
            'SELECT * FROM embed_settings WHERE video_id = :vid LIMIT 1',
            [':vid' => $video['id']]
        );

        $twig = TwigFactory::create();
        $effective = $loader->loadForVideo((int) $video['id']);
        $hasCustomAllowedOrigins = $overrideRaw !== null && ($overrideRaw['allowed_embed_origins'] ?? null) !== null;
        $html = $twig->render('video-embed.twig', [
            'video' => $video,
            'global' => $loader->normalize($globalRaw ?? []),
            'override' => $overrideRaw !== null ? $loader->normalize($overrideRaw) : null,
            'effective' => $effective,
            'has_custom_allowed_origins' => $hasCustomAllowedOrigins,
            'base_url' => Config::appBaseUrl(),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function videoSave(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid = $request->getAttribute('uuid');
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        if (!TwigFactory::validateCsrf($body['_csrf'] ?? '')) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $this->redirect($response, '/admin/videos/' . $uuid . '/embed');
        }

        $video = Connection::fetch(
            'SELECT id, uuid FROM videos WHERE uuid = :uuid',
            [':uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $this->redirect($response, '/admin/videos');
        }

        $videoId = (int) $video['id'];
        $settings = $this->buildVideoOverridePayload($body);
        $settings[':video_id'] = $videoId;

        $existing = Connection::fetch(
            'SELECT id FROM embed_settings WHERE video_id = :vid LIMIT 1',
            [':vid' => $videoId]
        );

        if ($existing !== null) {
            Connection::execute(
                'UPDATE embed_settings SET
                    force_disable_adblock = :force_disable_adblock,
                    preroll_url           = :preroll_url,
                    preroll_skip_after    = :preroll_skip_after,
                    preroll_click_url     = :preroll_click_url,
                    preroll_source_kind   = :preroll_source_kind,
                    postroll_url          = :postroll_url,
                    postroll_skip_after   = :postroll_skip_after,
                    postroll_click_url    = :postroll_click_url,
                    postroll_source_kind  = :postroll_source_kind,
                    midroll_cues          = :midroll_cues,
                    allowed_embed_origins = :allowed_embed_origins
                 WHERE video_id = :video_id',
                $settings
            );
        } else {
            Connection::execute(
                'INSERT INTO embed_settings
                    (video_id, force_disable_adblock,
                     preroll_url, preroll_skip_after, preroll_click_url, preroll_source_kind,
                     postroll_url, postroll_skip_after, postroll_click_url, postroll_source_kind,
                     midroll_cues, allowed_embed_origins)
                 VALUES
                    (:video_id, :force_disable_adblock,
                     :preroll_url, :preroll_skip_after, :preroll_click_url, :preroll_source_kind,
                     :postroll_url, :postroll_skip_after, :postroll_click_url, :postroll_source_kind,
                     :midroll_cues, :allowed_embed_origins)',
                $settings
            );
        }

        TwigFactory::flash('success', 'Per-video embed settings saved.');
        return $this->redirect($response, '/admin/videos/' . $uuid . '/embed');
    }

    public function videoDelete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid = $request->getAttribute('uuid');
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        if (!TwigFactory::validateCsrf($body['_csrf'] ?? '')) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $this->redirect($response, '/admin/videos/' . $uuid . '/embed');
        }

        $video = Connection::fetch(
            'SELECT id, uuid FROM videos WHERE uuid = :uuid',
            [':uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $this->redirect($response, '/admin/videos');
        }

        Connection::execute(
            'DELETE FROM embed_settings WHERE video_id = :vid',
            [':vid' => $video['id']]
        );

        TwigFactory::flash('success', 'Per-video override removed.');
        return $this->redirect($response, '/admin/videos/' . $uuid . '/embed');
    }

    public function analyticsView(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $viewRows = Connection::fetchAll(
            "SELECT v.uuid, v.original_name, al.action, COUNT(*) AS cnt
             FROM access_log al
             JOIN videos v ON v.id = al.video_id
             WHERE al.action IN ('watch_open', 'embed_open', 'playback_start', 'playback_error', 'original_fallback')
             GROUP BY al.video_id, al.action
             ORDER BY v.original_name, al.action"
        );

        $rollupRows = Connection::fetchAll(
            'SELECT v.uuid, v.original_name, i.position, i.event, COUNT(*) AS cnt
             FROM ad_impressions i
             JOIN videos v ON v.id = i.video_id
             GROUP BY i.video_id, i.position, i.event
             ORDER BY v.original_name, i.position, i.event'
        );

        $placementRows = Connection::fetchAll(
            "SELECT v.uuid,
                    v.original_name,
                    JSON_UNQUOTE(JSON_EXTRACT(al.details_json, '$.placement')) AS placement,
                    CASE al.action
                        WHEN 'ad_view' THEN 'view'
                        WHEN 'ad_click' THEN 'click'
                    END AS event,
                    COUNT(*) AS cnt
             FROM access_log al
             JOIN videos v ON v.id = al.video_id
             WHERE al.action IN ('ad_view', 'ad_click')
               AND JSON_EXTRACT(al.details_json, '$.placement') IS NOT NULL
             GROUP BY al.video_id, placement, event
             ORDER BY v.original_name, placement, event"
        );

        $byVideo = [];
        foreach ($viewRows as $row) {
            $uuid = $row['uuid'];
            $this->initializeAnalyticsVideo($byVideo, $uuid, (string) $row['original_name']);
            $byVideo[$uuid]['view_metrics'][(string) $row['action']] = (int) $row['cnt'];
        }

        foreach ($rollupRows as $row) {
            $uuid = $row['uuid'];
            $this->initializeAnalyticsVideo($byVideo, $uuid, (string) $row['original_name']);
            $byVideo[$uuid]['ad_positions'][(string) $row['position']][(string) $row['event']] = (int) $row['cnt'];
        }

        foreach ($placementRows as $row) {
            $placement = is_string($row['placement']) ? trim($row['placement']) : '';
            if ($placement === '') {
                continue;
            }

            $uuid = $row['uuid'];
            $this->initializeAnalyticsVideo($byVideo, $uuid, (string) $row['original_name']);
            $byVideo[$uuid]['ad_positions'][$placement][(string) $row['event']] = (int) $row['cnt'];
        }

        foreach ($byVideo as &$videoAnalytics) {
            $adViewCount = 0;
            $adClickCount = 0;

            foreach ($videoAnalytics['ad_positions'] as $placement => $events) {
                $adViewCount += (int) ($events['view'] ?? 0);
                $adViewCount += (int) ($events['start'] ?? 0);
                $adClickCount += (int) ($events['click'] ?? 0);
                $videoAnalytics['ad_positions'][$placement] = $this->normalizeAnalyticsEvents($events);
            }

            uksort(
                $videoAnalytics['ad_positions'],
                fn(string $left, string $right): int => $this->analyticsPlacementOrder($left) <=> $this->analyticsPlacementOrder($right)
                    ?: strcmp($left, $right)
            );

            $videoAnalytics['totals'] = [
                'ad_views' => $adViewCount,
                'ad_clicks' => $adClickCount,
            ];
        }
        unset($videoAnalytics);

        uasort(
            $byVideo,
            static fn(array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name'])
        );

        $twig = TwigFactory::create();
        $html = $twig->render('ad-analytics.twig', ['by_video' => $byVideo]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function buildGlobalSettingsPayload(array $body): array
    {
        return [
            ':accent_color' => $this->sanitizeColor((string) ($body['accent_color'] ?? '#FF0000')),
            ':logo_url' => $this->sanitizeNullableHttpUrl($body['logo_url'] ?? ''),
            ':logo_upload_b2_key' => null,
            ':logo_upload_original_name' => null,
            ':logo_position' => $this->sanitizePosition((string) ($body['logo_position'] ?? 'top-right')),
            ':title_visible' => isset($body['title_visible']) ? 1 : 0,
            ':force_disable_adblock' => isset($body['force_disable_adblock']) ? 1 : 0,
            ':preroll_url' => $this->sanitizeNullableHttpUrl($body['preroll_url'] ?? ''),
            ':preroll_skip_after' => $this->sanitizeSkipDelay($body['preroll_skip_after'] ?? 5),
            ':preroll_click_url' => $this->sanitizeNullableHttpUrl($body['preroll_click_url'] ?? ''),
            ':preroll_source_kind' => $this->sanitizeSourceKind($body['preroll_source_kind'] ?? 'none'),
            ':postroll_url' => $this->sanitizeNullableHttpUrl($body['postroll_url'] ?? ''),
            ':postroll_skip_after' => $this->sanitizeSkipDelay($body['postroll_skip_after'] ?? 5),
            ':postroll_click_url' => $this->sanitizeNullableHttpUrl($body['postroll_click_url'] ?? ''),
            ':postroll_source_kind' => $this->sanitizeSourceKind($body['postroll_source_kind'] ?? 'none'),
            ':midroll_cues' => $this->sanitizeMidrollCues($body['midroll_cues'] ?? ''),
            ':watch_top_banner_html' => $this->sanitizeRawHtml($body['watch_top_banner_html'] ?? ''),
            ':watch_bottom_banner_html' => $this->sanitizeRawHtml($body['watch_bottom_banner_html'] ?? ''),
            ':embed_banner_html' => $this->sanitizeRawHtml($body['embed_banner_html'] ?? ''),
            ':general_script_url' => $this->sanitizeNullableHttpUrl($body['general_script_url'] ?? ''),
            ':general_html_code' => $this->sanitizeRawHtml($body['general_html_code'] ?? ''),
            ':direct_play_url' => $this->sanitizeNullableHttpUrl($body['direct_play_url'] ?? ''),
            ':direct_play_mode' => $this->sanitizeDirectMode($body['direct_play_mode'] ?? 'popup'),
            ':direct_popup_bypass_iframe' => isset($body['direct_popup_bypass_iframe']) ? 1 : 0,
            ':allowed_embed_origins' => $this->sanitizeAllowedOriginsJson($body['allowed_embed_origins'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function buildVideoOverridePayload(array $body): array
    {
        return [
            ':force_disable_adblock' => isset($body['force_disable_adblock']) ? 1 : 0,
            ':preroll_url' => $this->sanitizeNullableHttpUrl($body['preroll_url'] ?? ''),
            ':preroll_skip_after' => $this->sanitizeSkipDelay($body['preroll_skip_after'] ?? 5),
            ':preroll_click_url' => $this->sanitizeNullableHttpUrl($body['preroll_click_url'] ?? ''),
            ':preroll_source_kind' => $this->sanitizeSourceKind($body['preroll_source_kind'] ?? 'none'),
            ':postroll_url' => $this->sanitizeNullableHttpUrl($body['postroll_url'] ?? ''),
            ':postroll_skip_after' => $this->sanitizeSkipDelay($body['postroll_skip_after'] ?? 5),
            ':postroll_click_url' => $this->sanitizeNullableHttpUrl($body['postroll_click_url'] ?? ''),
            ':postroll_source_kind' => $this->sanitizeSourceKind($body['postroll_source_kind'] ?? 'none'),
            ':midroll_cues' => $this->sanitizeMidrollCues($body['midroll_cues'] ?? ''),
            ':allowed_embed_origins' => isset($body['override_allowed_embed_origins'])
                ? $this->sanitizeAllowedOriginsJson($body['allowed_embed_origins'] ?? '')
                : null,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $byVideo
     */
    private function initializeAnalyticsVideo(array &$byVideo, string $uuid, string $name): void
    {
        if (isset($byVideo[$uuid])) {
            return;
        }

        $byVideo[$uuid] = [
            'name' => $name,
            'view_metrics' => [
                'watch_open' => 0,
                'embed_open' => 0,
                'playback_start' => 0,
                'playback_error' => 0,
                'original_fallback' => 0,
            ],
            'ad_positions' => [],
            'totals' => [
                'ad_views' => 0,
                'ad_clicks' => 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $events
     * @return array<string, int>
     */
    private function normalizeAnalyticsEvents(array $events): array
    {
        return [
            'view' => (int) ($events['view'] ?? 0),
            'start' => (int) ($events['start'] ?? 0),
            'complete' => (int) ($events['complete'] ?? 0),
            'skip' => (int) ($events['skip'] ?? 0),
            'click' => (int) ($events['click'] ?? 0),
        ];
    }

    private function analyticsPlacementOrder(string $placement): int
    {
        static $order = [
            'preroll' => 10,
            'midroll' => 20,
            'postroll' => 30,
            'direct_play' => 40,
            'watch_top_banner' => 50,
            'watch_bottom_banner' => 60,
            'embed_banner' => 70,
            'watch_general_html' => 80,
            'embed_general_html' => 90,
        ];

        return $order[$placement] ?? 999;
    }

    private function sanitizeAllowedOriginsJson(mixed $value): string
    {
        $origins = (new EmbedOriginService())->normalizeOriginList($value);
        return json_encode($origins, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function sanitizeColor(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#FF0000';
    }

    private function sanitizeNullableHttpUrl(mixed $url): ?string
    {
        $url = $this->sanitizeHttpUrl(trim((string) $url));
        return $url !== '' ? $url : null;
    }

    private function sanitizeHttpUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    private function sanitizePosition(string $pos): string
    {
        $valid = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
        return in_array($pos, $valid, true) ? $pos : 'top-right';
    }

    private function sanitizeSkipDelay(mixed $val): int
    {
        return max(0, min(30, (int) $val));
    }

    private function sanitizeSourceKind(mixed $value): string
    {
        return is_string($value) && in_array($value, ['none', 'mp4', 'vast'], true) ? $value : 'none';
    }

    private function sanitizeDirectMode(mixed $value): string
    {
        return is_string($value) && in_array($value, ['popup', 'redirect', 'iframe'], true)
            ? $value
            : 'popup';
    }

    private function sanitizeRawHtml(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function sanitizeMidrollCues(mixed $raw): ?string
    {
        $cues = $this->loader()->normalizeMidrollCues($raw);
        if ($cues === []) {
            return null;
        }

        return json_encode($cues, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, ?string>
     */
    private function resolveLogoUploadPayload(
        ?UploadedFileInterface $uploadedFile,
        array $existing,
        bool $removeRequested
    ): array {
        $existingKey = $this->normalizeNullableString($existing['logo_upload_b2_key'] ?? null);
        $existingName = $this->normalizeNullableString($existing['logo_upload_original_name'] ?? null);

        if ($uploadedFile === null || $uploadedFile->getError() === UPLOAD_ERR_NO_FILE) {
            if ($removeRequested && $existingKey !== null) {
                B2Client::delete($existingKey);
                return [
                    ':logo_upload_b2_key' => null,
                    ':logo_upload_original_name' => null,
                ];
            }

            return [
                ':logo_upload_b2_key' => $existingKey,
                ':logo_upload_original_name' => $existingName,
            ];
        }

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Logo upload failed. Please try again.');
        }

        if ($uploadedFile->getSize() !== null && $uploadedFile->getSize() > self::LOGO_MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('Logo upload exceeds the 2 MB limit.');
        }

        [$extension, $contentType] = $this->validateLogoUpload($uploadedFile);
        $key = 'branding/global/logo.' . $extension;
        $tmpPath = tempnam(sys_get_temp_dir(), 'logo_upload_');
        if ($tmpPath === false) {
            throw new \RuntimeException('Could not prepare temporary storage for the uploaded logo.');
        }

        try {
            $uploadedFile->moveTo($tmpPath);
            B2Client::put($key, $tmpPath, $contentType);
        } catch (\Throwable $e) {
            error_log('[EmbedSettingsController] Logo upload failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw new \RuntimeException('Could not upload the logo file.');
        } finally {
            @unlink($tmpPath);
        }

        if ($existingKey !== null && $existingKey !== $key) {
            B2Client::delete($existingKey);
        }

        return [
            ':logo_upload_b2_key' => $key,
            ':logo_upload_original_name' => mb_substr((string) $uploadedFile->getClientFilename(), 0, 255),
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function validateLogoUpload(UploadedFileInterface $uploadedFile): array
    {
        // Use content-based detection instead of trusting client-supplied MIME or filename.
        // Read an initial chunk large enough for magic-byte identification.
        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $sample = $stream->read(8192);
        $stream->rewind();

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = strtolower((string) $finfo->buffer($sample));

        $allowed = [
            'image/png'  => ['png', 'image/png'],
            'image/jpeg' => ['jpg', 'image/jpeg'],
        ];

        if (isset($allowed[$detectedMime])) {
            return $allowed[$detectedMime];
        }

        // SVG is rejected outright: it cannot be safely validated by magic bytes
        // and serving user-supplied SVG inline poses an XSS risk.
        throw new \RuntimeException('Unsupported logo format. Use PNG or JPG.');
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function loader(): EmbedSettingsLoader
    {
        return new EmbedSettingsLoader();
    }

    private function redirect(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', $url);
    }
}
