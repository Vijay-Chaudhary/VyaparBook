<?php
// app/Reminders/ReminderMessage.php

namespace App\Reminders;

use App\Support\Inr;

/**
 * Composes a payment-reminder message and its wa.me deep link (Phase 4a).
 *
 * Pure and side-effect free — no DB, no HTTP — so the wording and the phone
 * handling are testable in isolation. This is deliberately the ONLY place the
 * customer-facing text lives: Phase 4b sends the very same string through the
 * WhatsApp Cloud API, so a customer who was reminded by link in July and by API
 * in August reads the same words from a different sender.
 *
 * The locale here is the SHOP's (businesses.default_language), not the owner's
 * UI locale — the customer is the one reading it.
 */
final class ReminderMessage
{
    /**
     * Digits-only E.164 (no '+', as wa.me wants), or null when the number
     * cannot be trusted.
     *
     * Returning null rather than a best guess is the point: a wrong number
     * means a stranger receives a stranger's debt, so anything ambiguous is
     * refused and the UI shows "check this number" instead of a send button.
     */
    public static function normalisePhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        // 0091… (IDD prefix) and 09876… (local trunk prefix) both lead with 0.
        $digits = ltrim($digits, '0');

        // A bare 10-digit number is an Indian mobile; anything else must have
        // arrived with its own country code.
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }

        return preg_match('/^[1-9][0-9]{9,14}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * The message body, in the shop's language.
     *
     * @param  string  $shopName    the business name — never translated
     * @param  string  $amountDue   scale-2 decimal string
     * @param  string  $locale      'en' | 'hi'
     */
    public static function text(string $shopName, string $amountDue, string $locale): string
    {
        return trans('reminders.message', [
            'shop' => $shopName,
            'amount' => Inr::format($amountDue),
        ], $locale);
    }

    /**
     * The wa.me deep link, or null when the phone cannot be normalised — the
     * caller must treat null as "not sendable", never as an empty link.
     */
    public static function url(?string $phone, string $shopName, string $amountDue, string $locale): ?string
    {
        $e164 = self::normalisePhone($phone);

        if ($e164 === null) {
            return null;
        }

        return 'https://wa.me/'.$e164.'?text='.rawurlencode(self::text($shopName, $amountDue, $locale));
    }
}
