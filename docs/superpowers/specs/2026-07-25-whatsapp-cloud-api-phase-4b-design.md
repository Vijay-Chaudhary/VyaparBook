# WhatsApp Reminders, Cloud API Transport — Phase 4b Design

**Date:** 2026-07-25
**Status:** Draft (design); awaiting sign-off.
**Scope:** Phase 4b of WhatsApp payment reminders. Swaps the **transport** only:
the owner still reviews and taps, but the reminder is sent by the platform's
WhatsApp Business number through the Meta Cloud API instead of handing off to
the owner's own WhatsApp. Adds delivery status and inbound **STOP** handling.
**Unattended scheduling is explicitly not in this phase.**

---

## Background

Phase 4a shipped the product-shaped half — targeting, wording, the review list,
opt-out and the intent log — and deliberately left the transport as a `wa.me`
deep link so nothing was blocked on Meta onboarding. That seam is the whole
point of 4b: `ReminderMessage` already composes the text, `reminder_logs`
already records the send, and `channel` already distinguishes `wa_link` from
`cloud_api`.

What changes for the owner is small and what changes underneath is not. This is
the app's **first** outbound HTTP integration, **first** queued job, and
**first** webhook — there is no existing pattern in `app/` to copy, so 4b
establishes them.

## Decisions (locked in scoping)

1. **Built dark, behind a driver.** No Meta credentials exist yet. Sending goes
   through a `WhatsAppSender` interface with two implementations: `LogSender`
   (the default — records what *would* have been sent and returns a synthetic
   id) and `CloudApiSender` (the real thing). `config('services.whatsapp.driver')`
   selects; the default stays `log` until credentials are filled in, so merging
   this phase changes nothing in production until someone deliberately switches
   it on.

   **Honest limitation, stated up front:** every test uses `Http::fake`, which
   proves our request matches *our understanding* of Meta's API — not Meta's
   actual behaviour. The first live send needs a manual smoke test, and this
   phase cannot claim otherwise.

2. **Owner still approves each send.** The `/reminders` list and its per-customer
   button are unchanged. Only the effect of the tap changes: dispatch a queued
   job rather than redirect to `wa.me`. Nothing sends unattended, so quiet hours,
   rate limits, per-tenant automation opt-in and a cost meter are all still out
   of scope — they belong with scheduling.

3. **Sending is queued, never inline.** A Cloud API call is a network round-trip
   to a third party; doing it inside the request would make the owner wait on
   Meta and would lose the send if it timed out. `SendReminderJob` runs on the
   existing database queue with a retry policy. The `reminder_logs` row is
   written **before** dispatch with `status = 'queued'`, so a reminder is never
   invisible just because a worker is behind.

4. **Templates are the real constraint, and they bound the wording.** Meta only
   permits business-initiated messages outside a 24-hour customer-service window
   as **pre-approved templates** with positional variables. Free-form text will
   be rejected. So the 4a promise of "byte-identical wording" holds only if the
   approved template mirrors `lang/{en,hi}/reminders.php` exactly, with `:shop`
   and `:amount` registered as `{{1}}` and `{{2}}`. The template name and
   language map live in config; `ReminderMessage::text()` remains the source of
   truth for what we *believe* the customer reads, and is what we log and preview.
   A mismatch between our lang file and the approved template is a real
   operational risk and is called out in the runbook section rather than hidden.

5. **Delivery status is recorded but never trusted as consent.** Meta reports
   `sent`/`delivered`/`read`/`failed` asynchronously by webhook. These land on
   the existing `reminder_logs` row (`status`, `status_at`, `provider_message_id`,
   `error_code`, `error_message`). A `failed` status is operational information;
   it does not alter outstanding, and it never silently retries a message a
   customer may already have received.

6. **Inbound STOP opts the person out across every tenant that has their
   number.** This is the sharpest decision in the phase. The platform number
   receives replies for all tenants, and a person replying "STOP" has no way to
   say *which* shop they mean — they are telling **us** to stop messaging them.
   Honouring that only for the shop that happened to message last would keep
   messaging them from the others, which is the behaviour the instruction
   forbids. So an inbound stop-word sets `reminder_opt_out_at` on **every**
   non-archived customer with that phone number, in every tenant.

   This is a deliberate cross-tenant write — the only one in the app — and it is
   therefore done on the privileged connection, in one audited transaction, with
   the phone number as the sole selector. The alternative (per-tenant opt-out)
   was rejected as failing the person who asked. Recognised stop-words are
   configurable and cover English and Hindi (`STOP`, `UNSUBSCRIBE`, `बंद`, `रोको`).

7. **The webhook is signature-verified and unauthenticated by design.** Meta
   calls it with no session. `GET /webhooks/whatsapp` answers the subscription
   challenge with a verify token; `POST` verifies `X-Hub-Signature-256` (HMAC
   SHA-256 of the raw body with the app secret) and **rejects anything that
   fails** — an unverified payload could opt customers out or forge delivery
   status. It sits outside the tenant middleware entirely: it has no tenant
   context, and resolves what it needs from the message id.

## Architecture

```
owner taps Remind
  → ReminderController::send            (unchanged UI, new branch on driver)
      → writes reminder_logs (status=queued, channel=cloud_api)
      → dispatch(SendReminderJob)
          → WhatsAppSender::send()      (LogSender | CloudApiSender)
          → updates the row: status=sent + provider_message_id, or failed + error

Meta → POST /webhooks/whatsapp
  → verify X-Hub-Signature-256
  → statuses[]  → update reminder_logs by provider_message_id
  → messages[]  → stop-word? → opt out every customer with that phone, all tenants
```

### New pieces

| File | Role |
|---|---|
| `app/Reminders/Contracts/WhatsAppSender.php` | The interface: `send(string $toE164, string $text, string $locale): SendResult`. |
| `app/Reminders/SendResult.php` | Readonly VO: `accepted`, `providerMessageId`, `errorCode`, `errorMessage`. |
| `app/Reminders/LogSender.php` | Default driver. Logs and returns a synthetic id — safe by construction. |
| `app/Reminders/CloudApiSender.php` | Meta Graph API call, template payload, error mapping. |
| `app/Jobs/SendReminderJob.php` | The app's first queued job. Retries, backoff, updates the log row. |
| `app/Http/Controllers/WhatsAppWebhookController.php` | Challenge + status/inbound handling. |
| `app/Reminders/StopWords.php` | Recognises an opt-out reply in en/hi. |
| migration | `reminder_logs`: `status`, `status_at`, `provider_message_id`, `error_code`, `error_message`. |
| `config/services.php` | `whatsapp` block: driver, token, phone number id, api version, template names, verify token, app secret. |

### Configuration

```php
'whatsapp' => [
    'driver' => env('WHATSAPP_DRIVER', 'log'),      // 'log' until credentials exist
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'token' => env('WHATSAPP_TOKEN'),
    'template' => env('WHATSAPP_TEMPLATE', 'payment_reminder'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
],
```

## Out of scope (deferred to a scheduling phase)

Unattended/scheduled sending, quiet hours, per-tenant rate limits, per-tenant
opt-in to automation, cost metering and billing of message spend, per-tenant
WhatsApp numbers, and conversation/reply handling beyond stop-words.

## Error handling / edge cases

| Case | Behaviour |
|---|---|
| Driver is `log` (default) | Row goes `queued` → `sent` with a synthetic id; nothing leaves the box. |
| Meta returns 4xx (bad number, no template) | Row → `failed` with code/message; no retry, since retrying a rejection just repeats it. |
| Meta returns 5xx / timeout | Job retries with backoff; row stays `queued` until it resolves. |
| Webhook signature missing/wrong | `403`, nothing written. Covered by a test. |
| Webhook for an unknown message id | Ignored, logged — never creates a row. |
| Duplicate status callbacks | Idempotent: status only moves forward (`queued`→`sent`→`delivered`→`read`), never backwards. |
| Inbound STOP from an unknown number | Nothing to opt out; logged and ignored. |
| Customer opted out between tap and job running | Job re-checks and aborts without sending. |

## Testing

- **Unit** — `CloudApiSenderTest` (`Http::fake`): correct URL/version/phone id,
  bearer auth, template payload with positional params, success parsed into a
  `providerMessageId`, 4xx mapped to a non-retryable failure, 5xx surfaced for
  retry. `LogSenderTest`: never performs HTTP (`Http::preventStrayRequests`).
- **Unit** — `StopWordsTest`: en/hi stop-words, case/whitespace, and words that
  merely *contain* one (must not opt out).
- **Feature** — `SendReminderJobTest`: log row transitions, opted-out-in-flight
  abort, failure recorded.
- **Feature** — `WhatsAppWebhookTest`: challenge handshake, signature accepted/
  rejected, status update by message id, status never moves backwards, inbound
  STOP opts out **across tenants**, unknown id/number ignored.
- **Regression** — 4a's `RemindersTest` continues to pass with the default `log`
  driver, proving the phase is genuinely dark by default.

## Runbook — going live (for whoever has the credentials)

1. Create the Meta app + WhatsApp Business number; complete business verification.
2. Register the template `payment_reminder` in **en** and **hi**, with body text
   matching `lang/{en,hi}/reminders.php` `message`, `{{1}}` = shop, `{{2}}` = amount.
   **If the approved wording drifts from the lang file, the customer sees the
   template and our logs claim otherwise** — keep them in sync deliberately.
3. Fill the `WHATSAPP_*` env vars; set `WHATSAPP_DRIVER=cloud_api`.
4. Point Meta's webhook at `POST /webhooks/whatsapp`, using `WHATSAPP_VERIFY_TOKEN`.
5. Smoke-test to your own number, then confirm a `delivered` status lands on the
   `reminder_logs` row and that replying STOP opts you out.

## Traceability

- 4a spec Decision 1 (4a/4b split) → this phase changes only the transport.
- 4a spec Decision 3 (platform number) → `CloudApiSender` uses the single
  configured phone number id; no per-tenant credentials.
- 4a spec Decision 4 (log is the 4b foundation) → status columns extend the
  existing row; `channel` distinguishes the transports.
- 4a spec Decision 7 ("4b must extend opt-out with inbound STOP before it can
  ship") → Decision 6 here, deliberately cross-tenant.
- PRD §13 DPDP → STOP honoured immediately and recorded; no new personal data
  beyond what 4a stores.
