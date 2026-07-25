<?php
// app/Reminders/SendResult.php

namespace App\Reminders;

/**
 * The outcome of handing one reminder to a transport (Phase 4b).
 *
 * $retryable is the important distinction: a 4xx from Meta (bad number, no
 * such template) will fail identically forever, so retrying only burns quota
 * and delays the owner finding out. A 5xx or a timeout is worth another go.
 */
final readonly class SendResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerMessageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $retryable = false,
    ) {}

    public static function accepted(string $providerMessageId): self
    {
        return new self(accepted: true, providerMessageId: $providerMessageId);
    }

    public static function failed(string $code, string $message, bool $retryable = false): self
    {
        return new self(
            accepted: false,
            errorCode: $code,
            errorMessage: mb_substr($message, 0, 255),
            retryable: $retryable,
        );
    }
}
