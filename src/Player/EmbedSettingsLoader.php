<?php

declare(strict_types=1);

namespace VideoSystem\Player;

use VideoSystem\Database\Connection;

/**
 * Loads and normalizes embed/ad settings for public playback surfaces.
 *
 * Global-only settings (banners, general ads, direct ads) always come from the
 * global row. Per-video overrides only replace the player-level ad settings.
 */
final class EmbedSettingsLoader
{
    /** @var string[] */
    private const VIDEO_AD_OVERRIDE_FIELDS = [
        'force_disable_adblock',
        'preroll_source_kind',
        'preroll_url',
        'preroll_skip_after',
        'preroll_click_url',
        'postroll_source_kind',
        'postroll_url',
        'postroll_skip_after',
        'postroll_click_url',
        'midroll_cues',
    ];

    public function loadForVideo(int $videoId): array
    {
        $global = Connection::fetch(
            'SELECT * FROM embed_settings WHERE video_id IS NULL ORDER BY id ASC LIMIT 1'
        );

        $settings = $this->normalize($global ?? []);

        $override = Connection::fetch(
            'SELECT * FROM embed_settings WHERE video_id = :vid LIMIT 1',
            [':vid' => $videoId]
        );

        if ($override === null) {
            return $settings;
        }

        $hasAllowedEmbedOriginsOverride = array_key_exists('allowed_embed_origins', $override)
            && $override['allowed_embed_origins'] !== null;

        $override = $this->normalize($override);
        foreach (self::VIDEO_AD_OVERRIDE_FIELDS as $field) {
            $settings[$field] = $override[$field];
        }

        if ($hasAllowedEmbedOriginsOverride) {
            $settings['allowed_embed_origins'] = $override['allowed_embed_origins'];
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        $settings = array_replace($this->defaults(), array_intersect_key($row, $this->defaults()));

        $settings['title_visible'] = $this->toBool($settings['title_visible']);
        $settings['force_disable_adblock'] = $this->toBool($settings['force_disable_adblock']);
        $settings['direct_popup_bypass_iframe'] = $this->toBool($settings['direct_popup_bypass_iframe']);

        $settings['logo_url'] = $this->normalizeNullableString($settings['logo_url']);
        $settings['preroll_url'] = $this->normalizeNullableString($settings['preroll_url']);
        $settings['preroll_click_url'] = $this->normalizeNullableString($settings['preroll_click_url']);
        $settings['postroll_url'] = $this->normalizeNullableString($settings['postroll_url']);
        $settings['postroll_click_url'] = $this->normalizeNullableString($settings['postroll_click_url']);
        $settings['watch_top_banner_html'] = $this->normalizeNullableString($settings['watch_top_banner_html']);
        $settings['watch_bottom_banner_html'] = $this->normalizeNullableString($settings['watch_bottom_banner_html']);
        $settings['embed_banner_html'] = $this->normalizeNullableString($settings['embed_banner_html']);
        $settings['general_script_url'] = $this->normalizeNullableString($settings['general_script_url']);
        $settings['general_html_code'] = $this->normalizeNullableString($settings['general_html_code']);
        $settings['direct_play_url'] = $this->normalizeNullableString($settings['direct_play_url']);
        $settings['allowed_embed_origins'] = (new EmbedOriginService())->normalizeOriginList($settings['allowed_embed_origins']);

        $settings['logo_position'] = $this->normalizeLogoPosition((string) $settings['logo_position']);
        $settings['preroll_source_kind'] = $this->normalizeSourceKind(
            $settings['preroll_source_kind'],
            $settings['preroll_url']
        );
        $settings['postroll_source_kind'] = $this->normalizeSourceKind(
            $settings['postroll_source_kind'],
            $settings['postroll_url']
        );
        $settings['direct_play_mode'] = $this->normalizeDirectMode($settings['direct_play_mode']);

        $settings['preroll_skip_after'] = $this->normalizeSkipDelay($settings['preroll_skip_after']);
        $settings['postroll_skip_after'] = $this->normalizeSkipDelay($settings['postroll_skip_after']);
        $settings['midroll_cues'] = $this->normalizeMidrollCues($settings['midroll_cues']);

        return $settings;
    }

    /**
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
     */
    public function normalizeMidrollCues(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === '[]') {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = null;
        }

        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $cue) {
            if (!is_array($cue)) {
                continue;
            }

            $url = $this->normalizeNullableString($cue['url'] ?? null);
            if ($url === null) {
                continue;
            }

            $triggerKind = isset($cue['trigger_kind']) ? (string) $cue['trigger_kind'] : 'seconds';
            $triggerValue = $cue['trigger_value'] ?? null;
            $sourceKind = isset($cue['source_kind']) ? (string) $cue['source_kind'] : 'mp4';

            if (array_key_exists('time_sec', $cue)) {
                $triggerKind = 'seconds';
                $triggerValue = $cue['time_sec'];
                $sourceKind = 'mp4';
            }

            $triggerKind = in_array($triggerKind, ['seconds', 'percent'], true) ? $triggerKind : 'seconds';
            $triggerValue = (int) $triggerValue;

            if ($triggerKind === 'seconds') {
                if ($triggerValue < 0) {
                    continue;
                }
            } else {
                if ($triggerValue < 1 || $triggerValue > 99) {
                    continue;
                }
            }

            $normalized[] = [
                'trigger_kind' => $triggerKind,
                'trigger_value' => $triggerValue,
                'source_kind' => $this->normalizeCueSourceKind($sourceKind),
                'url' => $url,
                'skip_after' => $this->normalizeSkipDelay($cue['skip_after'] ?? 5),
                'click_url' => $this->normalizeNullableString($cue['click_url'] ?? null),
            ];
        }

        usort(
            $normalized,
            static function (array $left, array $right): int {
                return [$left['trigger_kind'], $left['trigger_value']]
                    <=> [$right['trigger_kind'], $right['trigger_value']];
            }
        );

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'logo_url' => null,
            'logo_position' => 'top-right',
            'accent_color' => '#FF0000',
            'title_visible' => true,
            'force_disable_adblock' => false,
            'preroll_url' => null,
            'preroll_skip_after' => 5,
            'preroll_click_url' => null,
            'preroll_source_kind' => 'none',
            'postroll_url' => null,
            'postroll_skip_after' => 5,
            'postroll_click_url' => null,
            'postroll_source_kind' => 'none',
            'midroll_cues' => [],
            'watch_top_banner_html' => null,
            'watch_bottom_banner_html' => null,
            'embed_banner_html' => null,
            'general_script_url' => null,
            'general_html_code' => null,
            'direct_play_url' => null,
            'direct_play_mode' => 'popup',
            'direct_popup_bypass_iframe' => true,
            'allowed_embed_origins' => [],
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeLogoPosition(string $value): string
    {
        $valid = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
        return in_array($value, $valid, true) ? $value : 'top-right';
    }

    private function normalizeSourceKind(mixed $kind, ?string $url): string
    {
        if (is_string($kind) && in_array($kind, ['none', 'mp4', 'vast'], true)) {
            return $kind;
        }

        return $url !== null ? 'mp4' : 'none';
    }

    private function normalizeCueSourceKind(mixed $kind): string
    {
        return is_string($kind) && in_array($kind, ['mp4', 'vast'], true) ? $kind : 'mp4';
    }

    private function normalizeDirectMode(mixed $value): string
    {
        return is_string($value) && in_array($value, ['popup', 'redirect', 'iframe'], true)
            ? $value
            : 'popup';
    }

    private function normalizeSkipDelay(mixed $value): int
    {
        return max(0, min(30, (int) $value));
    }

    private function toBool(mixed $value): bool
    {
        return (bool) $value;
    }
}
