<?php

namespace App\DTOs;

readonly class DeliveryResult
{
    private function __construct(
        public bool $success,
        public ?string $messageId,
        public ?string $errorMessage,
        public bool $isRetryable,
    ) {}

    public static function successful(string $messageId): self
    {
        return new self(
            success: true,
            messageId: $messageId,
            errorMessage: null,
            isRetryable: false,
        );
    }

    public static function failure(string $errorMessage, bool $isRetryable = true): self
    {
        return new self(
            success: false,
            messageId: null,
            errorMessage: $errorMessage,
            isRetryable: $isRetryable,
        );
    }
}
