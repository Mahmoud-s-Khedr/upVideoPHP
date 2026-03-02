<?php

declare(strict_types=1);

namespace VideoSystem\Upload;

final class ValidationException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        int $httpStatus = 422,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->getCode();
    }
}
