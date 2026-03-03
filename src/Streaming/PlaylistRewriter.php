<?php

declare(strict_types=1);

namespace VideoSystem\Streaming;

use VideoSystem\Config\Config;

/**
 * Rewrites HLS playlist URIs to route through the PHP delivery endpoint.
 *
 * Handles:
 *   - Relative and absolute segment URIs → /api/stream/{uuid}/{label}/{seg}.ts
 *   - Rendition playlist URIs in master.m3u8 → /api/stream/{uuid}/{label}/index.m3u8
 *   - #EXT-X-KEY URI → /api/keys/{uuid}/{index}  (token appended if in query-param mode)
 *
 * B2 bucket stays private — players never receive a direct B2 URL.
 */
final class PlaylistRewriter
{
    public function __construct(
        private readonly string $videoUuid,
        private readonly string $baseUrl,
    ) {}

    /**
     * Rewrite a master.m3u8 playlist.
     * Turns relative rendition URIs (e.g. "720p/index.m3u8") into delivery endpoint paths.
     *
     * @param string      $content    Raw master.m3u8 content from B2
     * @param string|null $tokenParam If non-null, append as ?token= to all rewritten URIs
     */
    public function rewriteMaster(string $content, ?string $tokenParam = null): string
    {
        $lines    = explode("\n", $content);
        $rewritten = [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (str_starts_with($line, '#EXT-X-MEDIA:') && str_contains($line, 'TYPE=SUBTITLES')) {
                // Subtitles are delivered as direct WebVTT tracks via bootstrap JSON.
                continue;
            } elseif (str_starts_with($line, '#EXT-X-MEDIA:') && str_contains($line, 'TYPE=AUDIO')) {
                // Audio renditions — rewrite URI if present
                $line = $this->rewriteAudioMediaUri($line, $tokenParam);
            } elseif (str_starts_with($line, '#EXT-X-STREAM-INF:') && str_contains($line, 'SUBTITLES=')) {
                $line = preg_replace('/,?SUBTITLES="[^"]*"/', '', $line) ?? $line;
            } elseif (!str_starts_with($line, '#') && str_ends_with($line, '.m3u8') && $line !== '') {
                // Rendition playlist URI: "720p/index.m3u8" or "{label}/index.m3u8"
                $label = explode('/', $line)[0];
                $line  = $this->deliveryUrl("stream/{$this->videoUuid}/{$label}/index.m3u8", $tokenParam);
            }

            $rewritten[] = $line;
        }

        return implode("\n", $rewritten);
    }

    /**
     * Rewrite a rendition-level playlist (e.g. 720p/index.m3u8).
     * Turns segment URIs into delivery endpoint paths.
     * Rewrites #EXT-X-KEY URI to the key endpoint.
     *
     * @param string $label        Rendition label ("720p", "1080p", etc.)
     */
    public function rewriteRendition(string $content, string $label, ?string $tokenParam = null): string
    {
        $lines    = explode("\n", $content);
        $rewritten = [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (str_starts_with($line, '#EXT-X-KEY:')) {
                $line = $this->rewriteKeyTag($line, $tokenParam);
            } elseif (!str_starts_with($line, '#') && str_ends_with($line, '.ts') && $line !== '') {
                $seg  = basename($line);
                $line = $this->deliveryUrl("stream/{$this->videoUuid}/{$label}/{$seg}", $tokenParam);
            }

            $rewritten[] = $line;
        }

        return implode("\n", $rewritten);
    }

    /**
     * Rewrite an audio-level playlist (e.g. audio_0/index.m3u8).
     * Turns segment URIs into delivery endpoint paths and rewrites key tags.
     */
    public function rewriteAudio(string $content, int $audioIndex, ?string $tokenParam = null): string
    {
        $lines    = explode("\n", $content);
        $rewritten = [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (str_starts_with($line, '#EXT-X-KEY:')) {
                $line = $this->rewriteKeyTag($line, $tokenParam);
            } elseif (!str_starts_with($line, '#') && str_ends_with($line, '.ts') && $line !== '') {
                $seg  = basename($line);
                $line = $this->deliveryUrl("stream/{$this->videoUuid}/audio_{$audioIndex}/{$seg}", $tokenParam);
            }

            $rewritten[] = $line;
        }

        return implode("\n", $rewritten);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function rewriteKeyTag(string $tag, ?string $tokenParam): string
    {
        // Replace URI="..." with the key delivery endpoint
        return preg_replace_callback(
            '/URI="([^"]*)"/',
            function (array $m) use ($tokenParam): string {
                // Extract key index from the original URL (e.g. .../keys/{uuid}/0)
                $keyIndex = 0;
                if (preg_match('#/(\d+)$#', $m[1], $im)) {
                    $keyIndex = (int) $im[1];
                }
                $uri = $this->deliveryUrl("keys/{$this->videoUuid}/{$keyIndex}", $tokenParam);
                return "URI=\"{$uri}\"";
            },
            $tag
        ) ?? $tag;
    }

    /**
     * Rewrite audio EXT-X-MEDIA URI to route through the audio endpoint.
     * Handles URIs like audio_0/index.m3u8 → /api/stream/{uuid}/audio_0/index.m3u8
     */
    private function rewriteAudioMediaUri(string $tag, ?string $tokenParam): string
    {
        return preg_replace_callback(
            '/URI="([^"]*)"/',
            function (array $m) use ($tokenParam): string {
                $original = $m[1];
                if (str_starts_with($original, 'http')) {
                    return "URI=\"{$original}\"";
                }
                // audio_0/index.m3u8 → stream/{uuid}/audio_0/index.m3u8
                $uri = $this->deliveryUrl("stream/{$this->videoUuid}/{$original}", $tokenParam);
                return "URI=\"{$uri}\"";
            },
            $tag
        ) ?? $tag;
    }

    private function deliveryUrl(string $path, ?string $tokenParam): string
    {
        $url = $this->baseUrl . '/api/' . $path;
        if ($tokenParam !== null) {
            $url .= '?token=' . urlencode($tokenParam);
        }
        return $url;
    }
}
