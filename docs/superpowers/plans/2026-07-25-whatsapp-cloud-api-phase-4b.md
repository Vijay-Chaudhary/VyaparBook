# WhatsApp Reminders, Cloud API Transport — Phase 4b Implementation Plan

**Goal:** Send the 4a reminder through the Meta Cloud API from the platform
number instead of handing off to the owner's WhatsApp, record delivery status,
and honour inbound STOP. Built **dark**: the `log` driver is the default, so
merging changes nothing until credentials are configured.

**Architecture:** `WhatsAppSender` interface (`LogSender` default,
`CloudApiSender` real) → `SendReminderJob` on the database queue → status
columns on the existing `reminder_logs` row. A signature-verified, unauthenticated
webhook handles delivery callbacks and stop-words.

**Tech Stack:** PHP 8.3 / Laravel 11.54, PostgreSQL (RLS), Pest, `Http::fake`.

**Spec:** `docs/superpowers/specs/2026-07-25-whatsapp-cloud-api-phase-4b-design.md`

---

## Before you start

```bash
git checkout master && git pull
git checkout -b feat/whatsapp-cloud-api-phase-4b
```

- Services running; baseline `php artisan test` → **558 passed**.
- No Meta credentials exist. Everything is tested with `Http::fake`; nothing in
  this plan performs a real network call.

### Scope decisions locked from the spec (do not re-litigate)

- Owner still approves every send. **No scheduler, no unattended sending.**
- Default driver stays `log`. A merge must not change production behaviour.
- Sending is queued, never inline; the log row is written **before** dispatch.
- Templates bound the wording (spec Decision 4) — the payload sends a template
  name + positional params, not free text.
- Inbound STOP opts the person out **across every tenant** holding that phone
  (spec Decision 6). Privileged connection, one audited transaction.
- Status only ever moves forward: `queued → sent → delivered → read`, plus
  `failed`. Never backwards, so out-of-order callbacks are safe.

---

## Task 1: Config, contracts and the two drivers (TDD)

- [x] **Step 1: `config/services.php`** — add the `whatsapp` block from the spec,
  `driver` defaulting to `log`.

- [x] **Step 2: `SendResult` VO + `WhatsAppSender` interface**

`send(string $toE164, string $text, string $locale): SendResult`. The interface
takes the *rendered* text so `ReminderMessage` stays the single source of
wording; `CloudApiSender` maps it onto template params internally.

- [x] **Step 3: write `tests/Unit/LogSenderTest.php` + `tests/Unit/CloudApiSenderTest.php` first**

`LogSender`: returns `accepted`, a synthetic id, and — asserted with
`Http::preventStrayRequests()` — performs **no** HTTP.
`CloudApiSender` with `Http::fake`: POSTs to
`https://graph.facebook.com/{version}/{phone_number_id}/messages`; bearer token
header; body carries `type: template`, the configured template name, the
locale's language code, and `{{1}}`/`{{2}}` as the shop and amount; a 200 parses
`messages[0].id` into `providerMessageId`; a 400 yields `accepted = false` with
the Meta error code **and** is marked non-retryable; a 500 is surfaced as
retryable.

- [x] **Step 4: run, watch fail, implement both drivers, run, pass.**

- [x] **Step 5: bind in a service provider** — `WhatsAppSender` resolves from
  `config('services.whatsapp.driver')`. Unknown driver → throw at boot rather
  than silently degrade to `log` (a typo must not look like success).

- [x] **Step 6: commit.**

---

## Task 2: Status columns + `SendReminderJob` (TDD)

- [x] **Step 1: migration** — add to `reminder_logs`: `status` string(12) default
  `'queued'`, `status_at` timestamp nullable, `provider_message_id` string(128)
  nullable **indexed** (the webhook looks rows up by it), `error_code` string(32)
  nullable, `error_message` string(255) nullable. Backfill existing 4a rows to
  `status = 'sent'` — they were handed to the owner's WhatsApp and are as
  "sent" as that channel can report.

- [x] **Step 2: write `tests/Feature/SendReminderJobTest.php` first**

Cover: a queued row becomes `sent` with the provider id; a 4xx failure becomes
`failed` with the code and is **not** retried; a customer who opted out between
the tap and the job running causes the job to abort **without** sending
(assert `Http::assertNothingSent()`); the job runs tenant-pinned and touches only
its own tenant's row.

- [x] **Step 3: implement `app/Jobs/SendReminderJob.php`**

Carries `reminderLogId` + `businessId` (ids, never models — the model would
serialise a tenant-scoped query into the payload). Pins the tenant itself, since
a queue worker has no request context. `tries = 3`, exponential `backoff`, and
`failed()` writes `status = 'failed'` so a dead job is never silent.

- [x] **Step 4: run, pass, commit.**

---

## Task 3: Wire the controller to the driver

- [ ] **Step 1: extend `RemindersTest`** — with the default `log` driver the 4a
  behaviour must be **unchanged** (still redirects to `wa.me`); with
  `cloud_api` configured the same tap writes a `queued` row with
  `channel = 'cloud_api'`, dispatches the job (`Queue::fake()`), and redirects
  back to `/reminders` with a confirmation instead of leaving the app.

- [ ] **Step 2: implement the branch in `ReminderController::send`.**

Keep the server-side opt-out/phone checks exactly as they are — they guard both
transports.

- [ ] **Step 3: show status on the list** — a sent/failed indicator per row from
  the latest `reminder_logs` entry, so the owner can see what happened. Add
  `lang/{en,hi}` keys for the statuses.

- [ ] **Step 4: run, pass, commit.**

---

## Task 4: The webhook (TDD)

- [ ] **Step 1: write `tests/Feature/WhatsAppWebhookTest.php` first**

- `GET` with the right `hub.verify_token` echoes `hub.challenge`; a wrong token
  is refused.
- `POST` with a valid `X-Hub-Signature-256` is accepted; a missing or wrong
  signature returns **403 and writes nothing** — assert the row is untouched.
- A `delivered` status updates the row found by `provider_message_id`.
- A late `sent` callback arriving after `delivered` does **not** move the status
  backwards.
- An inbound `STOP` opts out that phone's customers **in two different tenants**
  — the cross-tenant assertion is the point of this phase's riskiest decision.
- A stop-word embedded in an ordinary sentence does **not** opt out.
- Unknown message id / unknown number are ignored without error.

- [ ] **Step 2: `app/Reminders/StopWords.php`** — exact-match (after trim,
  case-fold, strip punctuation) against a configured en/hi list. Deliberately
  **not** a substring match: "please don't stop sending" must not opt out.

- [ ] **Step 3: implement `WhatsAppWebhookController`**

Raw-body HMAC compare with `hash_equals`, never `==`. Routed in `routes/web.php`
**outside** every auth/tenant group, and CSRF-exempt via
`$middleware->validateCsrfTokens(except: ['webhooks/whatsapp'])` in
`bootstrap/app.php`.

The cross-tenant opt-out runs on the privileged `pgsql_migrate` connection in
one transaction, selecting solely on `phone`, and writes a `PlatformAudit`
entry — a cross-tenant write must never be invisible.

- [ ] **Step 4: run, pass, commit.**

---

## Task 5: Full suite, docs, wrap-up

- [ ] **Step 1: `php artisan test`** — 558 baseline + the new cases, no regressions.
- [ ] **Step 2: `.env.example`** — add the `WHATSAPP_*` keys, commented, with
  `WHATSAPP_DRIVER=log` as the shipped default.
- [ ] **Step 3: `docs/ui-backlog.md`** — `F-05`, noting it ships dark.
- [ ] **Step 4: manual smoke test** — **cannot be done without credentials.**
  Record it as outstanding rather than ticking it; the spec's runbook is what
  whoever has the credentials follows.
- [ ] **Step 5: commit, PR, squash-merge** (`gh api` REST).

---

## Self-review notes (traceability to the spec)

- Decision 1 (dark by default) → `log` driver default + Task 3 Step 1 asserting
  unchanged 4a behaviour.
- Decision 2 (owner approves) → no scheduler anywhere in this plan.
- Decision 3 (queued) → row written before dispatch; Task 2.
- Decision 4 (templates) → template payload in `CloudApiSender`, runbook warns
  about wording drift.
- Decision 5 (status ≠ consent) → status columns only; `failed` never retries a
  possibly-delivered message, never touches outstanding.
- Decision 6 (cross-tenant STOP) → Task 4 Step 1's two-tenant assertion, audited
  privileged write.
- Decision 7 (signed webhook) → 403 test asserting nothing is written.
