<?php

declare(strict_types=1);

namespace VideoSystem\Player;

use Psr\Http\Message\ServerRequestInterface;

final class EmbedOriginService
{
    /**
     * @return list<string>
     */
    public function normalizeOriginList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = [];
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }

            if ($trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $items = $decoded;
                }
            } else {
                $items = preg_split('/\R+/', $trimmed) ?: [];
            }
        } elseif (is_array($value)) {
            $items = $value;
        }

        $origins = [];
        foreach ($items as $item) {
            $origin = $this->normalizeOrigin($item);
            if ($origin !== null) {
                $origins[$origin] = $origin;
            }
        }

        return array_values($origins);
    }

    public function normalizeOrigin(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            return null;
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port']) && is_int($parts['port'])) {
            $defaultPort = $scheme === 'https' ? 443 : 80;
            if ($parts['port'] !== $defaultPort) {
                $origin .= ':' . $parts['port'];
            }
        }

        return $origin;
    }

    /**
     * @param list<string> $allowedOrigins
     */
    public function isAllowed(array $allowedOrigins, ?string $origin): bool
    {
        return $origin !== null && in_array($origin, $allowedOrigins, true);
    }

    public function resolveRequestOrigin(ServerRequestInterface $request): ?string
    {
        $params = $request->getQueryParams();
        $candidates = [
            $params['parent_origin'] ?? null,
            $request->getHeaderLine('X-Embed-Parent-Origin'),
            $request->getHeaderLine('Origin'),
            $this->originFromReferer($request->getHeaderLine('Referer')),
        ];

        foreach ($candidates as $candidate) {
            $origin = $this->normalizeOrigin($candidate);
            if ($origin !== null) {
                return $origin;
            }
        }

        return null;
    }

    public function encodeOriginList(array $origins): ?string
    {
        $normalized = $this->normalizeOriginList($origins);
        if ($normalized === []) {
            return null;
        }

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function originFromReferer(string $referer): ?string
    {
        return $this->normalizeOrigin($referer);
    }
}
