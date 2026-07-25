<?php
// app/Reminders/LogSender.php

namespace App\Reminders;

use App\Reminders\Contracts\WhatsAppSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default transport: records what WOULD have been sent and sends nothing.
 *
 * This is what makes Phase 4b safe to merge with no Meta credentials — the
 * integration ships dark, and switching it on is a deliberate config change
 * rather than a side effect of deploying. It is also what the test suite runs
 * against, so no test can accidentally depend on a real network call.
 */
final class LogSender implements WhatsAppSender
{
    public function send(string $toE164, string $text, string $locale, array $templateParams = []): SendResult
    {
        // The phone is personal data; log the fact and the tail, not the number.
        Log::info('whatsapp.reminder.suppressed', [
            'to_suffix' => Str::substr($toE164, -4),
            'locale' => $locale,
            'chars' => Str::length($text),
            'driver' => 'log',
        ]);

        return SendResult::accepted('log-'.Str::uuid());
    }
}
