<?php
// app/Http/Controllers/WhatsAppWebhookController.php

namespace App\Http\Controllers;

use App\Platform\PlatformAudit;
use App\Reminders\StopWords;
use App\Reminders\WhatsAppConfig;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Meta's callbacks for WhatsApp reminders (Phase 4b): delivery status and
 * inbound replies.
 *
 * Unauthenticated by necessity — Meta has no session — and therefore
 * signature-verified without exception. An unverified payload could forge
 * delivery status or, far worse, opt customers out; so a bad signature is a
 * 403 that writes nothing at all.
 *
 * Runs entirely OUTSIDE the tenant middleware. It has no tenant context: a
 * status callback finds its row by provider_message_id, and an inbound STOP
 * deliberately spans every tenant (see optOutEverywhere).
 */
class WhatsAppWebhookController extends Controller
{
    /** Meta's subscription handshake. */
    public function verify(Request $request): Response
    {
        $token = (string) WhatsAppConfig::get('verify_token');
        $supplied = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));

        if ($token === '' || ! hash_equals($token, $supplied)) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge', '')), 200);
    }

    public function handle(Request $request): Response
    {
        if (! $this->signatureValid($request)) {
            // Log the rejection but never the body: an unverified payload is
            // attacker-controlled and may carry someone else's phone number.
            Log::warning('whatsapp.webhook.bad_signature', ['ip' => $request->ip()]);

            return response('Forbidden', 403);
        }

        $payload = $request->json()->all();

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->applyStatus($status);
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $this->applyInbound($message);
                }
            }
        }

        // Always 200 once verified: Meta retries anything else, and a payload we
        // could not match is not a failure on their side.
        return response('', 200);
    }

    /** HMAC-SHA256 of the RAW body, compared in constant time. */
    private function signatureValid(Request $request): bool
    {
        $secret = (string) WhatsAppConfig::get('app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '' || $header === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /**
     * How far along a message is. Statuses arrive out of order, so this only
     * ever moves forward — a late 'sent' after 'delivered' must not rewind the
     * row and make a delivered message look in flight.
     */
    private const RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    private function applyStatus(array $status): void
    {
        $id = (string) ($status['id'] ?? '');
        $newStatus = (string) ($status['status'] ?? '');

        if ($id === '' || ! array_key_exists($newStatus, self::RANK)) {
            return;
        }

        // Privileged connection: this row belongs to a tenant we have no pin
        // for, and Meta's callback is the only thing that can resolve it.
        $row = DB::table('reminder_logs')
            ->where('provider_message_id', $id)->first();

        if ($row === null) {
            Log::info('whatsapp.webhook.unknown_message', ['status' => $newStatus]);

            return;
        }

        $current = self::RANK[$row->status] ?? 0;

        // 'failed' is terminal information and always recorded; otherwise only
        // forward moves.
        if ($newStatus !== 'failed' && self::RANK[$newStatus] <= $current) {
            return;
        }

        DB::table('reminder_logs')->where('id', $row->id)->update([
            'status' => $newStatus,
            'status_at' => now(),
            'error_code' => isset($status['errors'][0]['code']) ? (string) $status['errors'][0]['code'] : null,
            'error_message' => isset($status['errors'][0]['title'])
                ? mb_substr((string) $status['errors'][0]['title'], 0, 255)
                : null,
            'updated_at' => now(),
        ]);
    }

    private function applyInbound(array $message): void
    {
        $from = preg_replace('/\D+/', '', (string) ($message['from'] ?? '')) ?? '';
        $body = $message['text']['body'] ?? null;

        if ($from === '' || ! StopWords::isStop($body)) {
            return;
        }

        $this->optOutEverywhere($from);
    }

    /**
     * Opt this person out of reminders in EVERY tenant holding their number.
     *
     * The sharpest decision in Phase 4b. Replies arrive at one platform number
     * for all tenants, and someone replying STOP cannot say which shop they
     * mean — they are telling US to stop. Honouring it for only the shop that
     * messaged last would keep the others messaging them, which is precisely
     * what they asked us not to do.
     *
     * Therefore a deliberate cross-tenant write — the only one in the app. It
     * runs inside Tenancy::withoutTenant(), selects solely on the phone number,
     * and is audited, because a write that crosses tenants must never be
     * invisible.
     */
    private function optOutEverywhere(string $e164): void
    {
        // Customers are stored as the shop typed them ('9876543210',
        // '+91 98765 43210'), so match on the last 10 digits rather than
        // expecting a normalised column.
        $local = mb_substr($e164, -10);

        Tenancy::withoutTenant(fn () => DB::transaction(function () use ($local, $e164) {
            $ids = DB::table('customers')
                ->whereNull('archived_at')
                ->whereNull('reminder_opt_out_at')
                // MySQL's REGEXP_REPLACE is global by default, so Postgres's
                // trailing 'g' flag has no equivalent and an explicit class
                // replaces \D.
                ->whereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '') LIKE ?", ['%'.$local])
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            DB::table('customers')
                ->whereIn('id', $ids)
                ->update(['reminder_opt_out_at' => now(), 'updated_at' => now()]);

            PlatformAudit::record('whatsapp_stop_opt_out', null, [
                'phone_suffix' => mb_substr($e164, -4),
                'customers' => $ids->count(),
            ]);
        }));
    }
}
