<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Player\EmbedOriginService;
use VideoSystem\Player\EmbedSettingsLoader;

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
    public function globalForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rawSettings = Connection::fetch(
            'SELECT * FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1'
        );

        $twig = TwigFactory::create();
        $html = $twig->render('embed-settings.twig', [
            'settings' => $this->loader()->normalize($rawSettings ?? []),
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

        $settings = $this->buildGlobalSettingsPayload($body);
        $existing = Connection::fetch('SELECT id FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1');

        if ($existing !== null) {
            Connection::execute(
                'UPDATE embed_settings SET
                    accent_color               = :accent_color,
                    logo_url                   = :logo_url,
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
                    (video_id, accent_color, logo_url, logo_position, title_visible, force_disable_adblock,
                     preroll_url, preroll_skip_after, preroll_click_url, preroll_source_kind,
                     postroll_url, postroll_skip_after, postroll_click_url, postroll_source_kind, midroll_cues,
                     watch_top_banner_html, watch_bottom_banner_html, embed_banner_html,
                     general_script_url, general_html_code,
                     direct_play_url, direct_play_mode, direct_popup_bypass_iframe,
                     allowed_embed_origins)
                 VALUES
                    (NULL, :accent_color, :logo_url, :logo_position, :title_visible, :force_disable_adblock,
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
        $rows = Connection::fetchAll(
            'SELECT v.uuid, v.original_name, i.position, i.event, COUNT(*) AS cnt
             FROM ad_impressions i
             JOIN videos v ON v.id = i.video_id
             GROUP BY i.video_id, i.position, i.event
             ORDER BY v.original_name, i.position, i.event'
        );

        $byVideo = [];
        foreach ($rows as $row) {
            $uuid = $row['uuid'];
            if (!isset($byVideo[$uuid])) {
                $byVideo[$uuid] = ['name' => $row['original_name'], 'positions' => []];
            }
            $byVideo[$uuid]['positions'][$row['position']][$row['event']] = (int) $row['cnt'];
        }

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

    private function loader(): EmbedSettingsLoader
    {
        return new EmbedSettingsLoader();
    }

    private function redirect(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', $url);
    }
}
