<?php

declare(strict_types=1);

namespace VideoSystem\Player;

final class PlayerSession
{
    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
