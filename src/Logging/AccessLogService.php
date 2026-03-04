<?php

declare(strict_types=1);

namespace VideoSystem\Logging;

use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

final class AccessLogService
{
    /** @var list<string> */
    public const EVENT_ACTIONS = [
        'watch_open',
        'embed_open',
        'embed_denied',
        'playback_start',
        'playback_error',
        'original_fallback',
        'ad_view',
        'ad_click',
    ];

    /**
     * @param array<string, mixed> $details
     */
    public function log(
        int $videoId,
        ServerRequestInterface $request,
        string $action,
        ?string $sessionId = null,
        ?int $keyIndex = null,
        array $details = [],
    ): void {
        try {
            Connection::execute(
                'INSERT INTO access_log (video_id, ip_address, session_id, key_index, action, details_json)
                 VALUES (:video_id, :ip_address, :session_id, :key_index, :action, :details_json)',
                [
                    ':video_id' => $videoId,
                    ':ip_address' => $this->resolveClientIp($request),
                    ':session_id' => $sessionId,
                    ':key_index' => $keyIndex,
                    ':action' => $action,
                    ':details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]
            );
        } catch (\Throwable) {
            // Best-effort logging only.
        }
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = (string) ($serverParams['REMOTE_ADDR'] ?? '');
        $trusted = Config::trustedProxies();

        if ($trusted !== [] && in_array($remoteAddr, $trusted, true)) {
            $xff = $request->getHeaderLine('X-Forwarded-For');
            if ($xff !== '') {
                return trim(explode(',', $xff)[0]);
            }
        }

        return $remoteAddr;
    }
}
