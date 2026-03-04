<?php

declare(strict_types=1);

namespace VideoSystem\Encoding;

use VideoSystem\Database\Connection;

/**
 * Live source of truth for the encoding rendition ladder.
 *
 * Reads the rendition_ladder table (populated by migration 012) and returns
 * the same key → params structure that was previously the RENDITION_LADDER
 * constant in RenditionPipeline.
 *
 * Results are cached for the lifetime of the PHP process so that every
 * rendition pass in a single worker run hits the DB only once.
 *
 * Falls back to hardcoded defaults if the table is empty or unavailable,
 * ensuring forward-compatibility with deployments that haven't run migration
 * 012 yet.
 */
final class RenditionLadder
{
    /** @var array<string, array{width:int,height:int,crf:int,vbitrate:string,abitrate:string}>|null */
    private static ?array $cache = null;

    /**
     * Hardcoded fallback — mirrors the previous RENDITION_LADDER constant.
     *
     * @var array<string, array{width:int,height:int,crf:int,vbitrate:string,abitrate:string}>
     */
    private static array $defaults = [
        '1080p' => ['width' => 1920, 'height' => 1080, 'crf' => 25, 'vbitrate' => '3000k', 'abitrate' => '192k'],
        '720p'  => ['width' => 1280, 'height' => 720,  'crf' => 26, 'vbitrate' => '2200k', 'abitrate' => '128k'],
        '540p'  => ['width' => 960,  'height' => 540,  'crf' => 27, 'vbitrate' => '1500k', 'abitrate' => '128k'],
        '480p'  => ['width' => 854,  'height' => 480,  'crf' => 28, 'vbitrate' => '1000k', 'abitrate' => '128k'],
        '360p'  => ['width' => 640,  'height' => 360,  'crf' => 29, 'vbitrate' => '500k',  'abitrate' => '96k'],
    ];

    /**
     * Returns the full ladder array, keyed by label (e.g. '1080p').
     *
     * Labels are sorted in the order defined by the `position` column.
     *
     * @return array<string, array{width:int,height:int,crf:int,vbitrate:string,abitrate:string}>
     */
    public static function getLadder(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $rows = Connection::fetchAll(
                'SELECT label, width, height, crf, vbitrate, abitrate
                   FROM rendition_ladder
                  ORDER BY position ASC, id ASC'
            );
        } catch (\Throwable) {
            // Table missing (migration not yet applied) — return hardcoded defaults.
            return self::$defaults;
        }

        if (empty($rows)) {
            return self::$defaults;
        }

        $ladder = [];
        foreach ($rows as $row) {
            $ladder[(string) $row['label']] = [
                'width'    => (int) $row['width'],
                'height'   => (int) $row['height'],
                'crf'      => (int) $row['crf'],
                'vbitrate' => (string) $row['vbitrate'],
                'abitrate' => (string) $row['abitrate'],
            ];
        }

        self::$cache = $ladder;
        return self::$cache;
    }

    /**
     * Returns just the ordered list of label strings.
     *
     * Replaces the former QUALITY_LABELS constants in VideoUploadService and
     * UploadInitController.
     *
     * @return list<string>
     */
    public static function getLabels(): array
    {
        return array_keys(self::getLadder());
    }

    /**
     * Clears the in-process cache.
     *
     * Must be called after the admin saves changes so the next encode job or
     * page render picks up the updated values.
     */
    public static function invalidate(): void
    {
        self::$cache = null;
    }
}
