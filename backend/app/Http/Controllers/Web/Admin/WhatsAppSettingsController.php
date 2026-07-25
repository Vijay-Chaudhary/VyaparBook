<?php
// app/Http/Controllers/Web/Admin/WhatsAppSettingsController.php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformWhatsAppSettings;
use App\Platform\PlatformAudit;
use App\Reminders\CloudApiSender;
use App\Reminders\ReminderMessage;
use App\Reminders\WhatsAppConfig;
use App\Support\Inr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The platform's WhatsApp credentials, editable from the console instead of
 * .env + a redeploy.
 *
 * Superadmin-only (the route sits in the platform_admin.web group). There is
 * ONE configuration because there is one platform number — no per-tenant
 * credentials (Phase 4a Decision 3).
 *
 * Secrets are write-only here: stored encrypted, never rendered back, and a
 * blank field means "keep what is stored" rather than "erase it". A secret a
 * page can display is a secret that leaks through a screenshot or a support
 * session.
 */
class WhatsAppSettingsController extends Controller
{
    /** The three fields that are never echoed back to the browser. */
    private const SECRETS = ['token', 'verify_token', 'app_secret'];

    public function edit(): View
    {
        $settings = PlatformWhatsAppSettings::current();

        return view('admin.console.whatsapp', [
            'settings' => $settings,
            // Which fields have a secret stored at all — enough for the operator
            // to know the state without exposing the value.
            'hasSecret' => collect(self::SECRETS)
                ->mapWithKeys(fn (string $k) => [$k => filled($settings?->{$k})])
                ->all(),
            // Where each live value actually comes from, so nobody debugs a
            // stale token that a forgotten .env is still supplying.
            'sources' => collect(['driver', 'api_version', 'phone_number_id', 'template', 'token', 'verify_token', 'app_secret'])
                ->mapWithKeys(fn (string $k) => [$k => WhatsAppConfig::source($k)])
                ->all(),
            'liveDriver' => WhatsAppConfig::driver(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'driver' => ['required', Rule::in(['log', 'cloud_api'])],
            'api_version' => ['nullable', 'string', 'max:12'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'template' => ['nullable', 'string', 'max:64'],
            'token' => ['nullable', 'string'],
            'verify_token' => ['nullable', 'string'],
            'app_secret' => ['nullable', 'string'],
        ]);

        $settings = PlatformWhatsAppSettings::current() ?? new PlatformWhatsAppSettings;

        foreach (['driver', 'api_version', 'phone_number_id', 'template'] as $field) {
            $settings->{$field} = $data[$field] ?? null;
        }

        // Blank means "leave it alone". Wiping a working credential because a
        // form was submitted without re-typing it would be an easy outage.
        foreach (self::SECRETS as $secret) {
            if (filled($data[$secret] ?? null)) {
                $settings->{$secret} = $data[$secret];
            }
        }

        $settings->updated_by = (int) auth()->id();
        $settings->save();

        WhatsAppConfig::flush();

        // Audit that they changed, never what they are.
        PlatformAudit::record('whatsapp_settings_saved', null, [
            'driver' => $settings->driver,
            'secrets_changed' => collect(self::SECRETS)
                ->filter(fn (string $s) => filled($data[$s] ?? null))
                ->values()->all(),
        ]);

        return redirect()->route('admin.console.whatsapp')->with('console_status', 'saved');
    }

    /**
     * Send a real reminder to a number of the operator's choosing.
     *
     * Deliberately a real send: the whole point is to verify our assumptions
     * against Meta rather than against Http::fake. This is the Phase 4b smoke
     * test that 4c names as a precondition for enabling automation.
     */
    public function test(Request $request): RedirectResponse
    {
        $request->validate([
            'to' => ['required', 'string', function ($attribute, $value, $fail) {
                if (ReminderMessage::normalisePhone($value) === null) {
                    $fail(__('admin.whatsapp_test_bad_number'));
                }
            }],
        ]);

        if (WhatsAppConfig::driver() !== 'cloud_api') {
            // Testing the log driver would report success without sending
            // anything — worse than no test, because it looks like proof.
            return redirect()->route('admin.console.whatsapp')
                ->with('whatsapp_test', ['ok' => false, 'code' => null, 'message' => __('admin.whatsapp_test_needs_cloud')]);
        }

        $e164 = (string) ReminderMessage::normalisePhone($request->string('to')->value());
        $shop = config('app.name');
        $amount = '100.00';

        $result = (new CloudApiSender)->send(
            $e164,
            ReminderMessage::text($shop, $amount, 'en'),
            'en',
            [$shop, Inr::format($amount)],
        );

        PlatformAudit::record('whatsapp_test_send', null, [
            'to_suffix' => substr($e164, -4),
            'accepted' => $result->accepted,
            'error_code' => $result->errorCode,
        ]);

        return redirect()->route('admin.console.whatsapp')->with('whatsapp_test', [
            'ok' => $result->accepted,
            'code' => $result->errorCode,
            // Verbatim: a truncated integration error is useless when the thing
            // you are debugging is somebody else's API.
            'message' => $result->accepted ? $result->providerMessageId : $result->errorMessage,
        ]);
    }
}
