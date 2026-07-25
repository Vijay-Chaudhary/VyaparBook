<?php
// app/Reminders/Contracts/WhatsAppSender.php

namespace App\Reminders\Contracts;

use App\Reminders\SendResult;

/**
 * How a reminder physically leaves the building (Phase 4b).
 *
 * Takes the ALREADY-RENDERED text so App\Reminders\ReminderMessage remains the
 * one source of wording across both phases; a template-based transport maps
 * that text onto approved template parameters internally rather than composing
 * its own.
 */
interface WhatsAppSender
{
    /**
     * @param  string  $toE164   digits-only destination, as ReminderMessage normalises it
     * @param  string  $text     the rendered message, for logging/preview and non-template transports
     * @param  string  $locale   'en' | 'hi' — selects the template language
     * @param  array<int, string>  $templateParams  positional {{1}}, {{2}}, … values
     */
    public function send(string $toE164, string $text, string $locale, array $templateParams = []): SendResult;
}
