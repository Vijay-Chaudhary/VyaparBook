<?php
// app/Reminders/WhatsAppConfig.php

namespace App\Reminders;

use App\Models\PlatformWhatsAppSettings;
use Throwable;

/**
 * The one answer to "what is the live WhatsApp configuration".
 *
 * Two sources of truth is a real hazard, so the rule is explicit and narrow:
 * a NON-EMPTY value stored by a superadmin in the console wins; otherwise the
 * .env value applies. Resolution is per field, so a half-filled console row
 * cannot blank out settings that env is still supplying correctly.
 *
 * source() exists so the console can label each field with where its live value
 * actually comes from — without that, someone eventually debugs a stale token
 * that a forgotten .env was quietly still providing.
 */
final class WhatsAppConfig
{
    /**
     * Per-request memo key. Held in the CONTAINER, not a static: a static would
     * survive between tests in one process and leak one test's settings into
     * the next, while the container is rebuilt per request and per test.
     */
    private const MEMO = 'whatsapp.config.stored';

    public static function get(string $key): ?string
    {
        $stored = self::stored()[$key] ?? null;

        if (is_string($stored) && trim($stored) !== '') {
            return $stored;
        }

        $value = config("services.whatsapp.{$key}");

        return $value === null ? null : (string) $value;
    }

    public static function driver(): string
    {
        return self::get('driver') ?? 'log';
    }

    /** 'console' when the live value is stored, 'env' when it comes from config. */
    public static function source(string $key): string
    {
        $stored = self::stored()[$key] ?? null;

        return is_string($stored) && trim($stored) !== '' ? 'console' : 'env';
    }

    /** Forget the memo — call after saving settings. */
    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        if (app()->bound(self::MEMO)) {
            return app()->make(self::MEMO);
        }

        try {
            $row = PlatformWhatsAppSettings::current();
        } catch (Throwable) {
            // Before the migration has run (or with no database at all, as in
            // some console contexts) the app must still boot on .env alone.
            app()->instance(self::MEMO, []);

            return [];
        }

        $stored = $row === null ? [] : [
            'driver' => $row->driver,
            'api_version' => $row->api_version,
            'phone_number_id' => $row->phone_number_id,
            'token' => $row->token,
            'template' => $row->template,
            'verify_token' => $row->verify_token,
            'app_secret' => $row->app_secret,
        ];

        app()->instance(self::MEMO, $stored);

        return $stored;
    }
}
