<?php
// app/Reminders/CloudApiSender.php

namespace App\Reminders;

use App\Reminders\Contracts\WhatsAppSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The real transport: Meta's WhatsApp Cloud API, from the single platform
 * business number (Phase 4a spec, Decision 3 — no per-tenant credentials).
 *
 * Meta refuses free-form business-initiated messages, so this sends a
 * PRE-APPROVED TEMPLATE with positional parameters. The rendered $text is
 * carried for logging and preview only; what the customer actually reads is
 * the approved template body, which must be kept in step with
 * lang/{en,hi}/reminders.php — see the runbook in the Phase 4b spec.
 *
 * Never throws on a failed send: the job needs a decision (retry or not), not
 * an exception, and a transport that throws on a 400 turns a bad phone number
 * into a dead job.
 */
final class CloudApiSender implements WhatsAppSender
{
    public function send(string $toE164, string $text, string $locale, array $templateParams = []): SendResult
    {
        $version = (string) WhatsAppConfig::get('api_version');
        $phoneNumberId = (string) WhatsAppConfig::get('phone_number_id');
        $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";

        try {
            $response = Http::withToken((string) WhatsAppConfig::get('token'))
                ->asJson()
                ->timeout(15)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $toE164,
                    'type' => 'template',
                    'template' => [
                        'name' => (string) WhatsAppConfig::get('template'),
                        'language' => ['code' => $locale],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => array_map(
                                fn (string $p) => ['type' => 'text', 'text' => $p],
                                array_values($templateParams),
                            ),
                        ]],
                    ],
                ]);
        } catch (ConnectionException $e) {
            // Never reached Meta at all — always worth another attempt.
            return SendResult::failed('connection', $e->getMessage(), retryable: true);
        }

        if ($response->successful()) {
            $id = (string) ($response->json('messages.0.id') ?? '');

            return $id === ''
                ? SendResult::failed('no_message_id', 'Accepted without a message id', retryable: true)
                : SendResult::accepted($id);
        }

        $code = (string) ($response->json('error.code') ?? $response->status());
        $message = (string) ($response->json('error.message') ?? 'WhatsApp send failed');

        // 4xx will fail identically forever (bad number, missing template):
        // retrying burns quota and delays the owner learning it did not work.
        return SendResult::failed($code, $message, retryable: $response->serverError());
    }
}
