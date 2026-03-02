<?php

declare(strict_types=1);

namespace VideoSystem\Auth;

/**
 * Value object holding the verified claims from an embed token.
 */
final class EmbedTokenClaims
{
    public function __construct(
        public readonly string $videoUuid,
        public readonly string $parentOrigin,
        public readonly string $viewerRef,
        public readonly int    $expiresAt,
    ) {}
}
