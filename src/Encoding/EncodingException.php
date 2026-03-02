<?php

declare(strict_types=1);

namespace VideoSystem\Encoding;

final class EncodingException extends \RuntimeException
{
    public function __construct(string $message, private readonly bool $nonRetryable = false)
    {
        parent::__construct($message);
    }

    public function isNonRetryable(): bool
    {
        return $this->nonRetryable;
    }
}
