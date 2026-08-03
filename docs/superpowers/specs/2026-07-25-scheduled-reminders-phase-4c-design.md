# Scheduled Reminders — Phase 4c Design

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

**Date:** 2026-07-25
**Status:** Draft (design); awaiting sign-off.
**Scope:** Unattended reminder sending on a schedule, with an owner-visible
preview the owner can cancel from, a per-customer cooldown, a per-tenant daily
cap, and quiet hours. Adds the app's first scheduler.

---

## Precondition (not a footnote)

**Automation must not be switched on for a tenant until the Phase 4b smoke test
has been done against the real Meta API.** Every Cloud API assumption in 4b is
verified only against `Http::fake`. While a human taps each send, a wrong
assumption surfaces one message at a time; a scheduler turns the same mistake
into a whole book of customers messaged wrongly before anyone notices.

This phase therefore ships doubly dark: automation is **off per tenant by
default**, and the planner refuses to dispatch at all unless
`services.whatsapp.driver === 'cloud_api'`. Switching both on is a deliberate,
auditable act.

## Background

Phases 4a/4b built everything except the trigger: who to chase (`ReminderService`),
what to say (`ReminderMessage`), how to send it (`WhatsAppSender`), and what
happened (`reminder_logs`). A human still has to open `/reminders` and tap.

That ceiling is real — the shops most in need of this are the least likely to
log in daily. But automation is also where reminders stop being a helpful
feature and start being a way to get a WhatsApp number reported and blocked, so
the whole phase is shaped by restraint rather than reach.

## Decisions (locked in scoping)

1. **Preview, then auto-send unless cancelled.** A planning run builds tomorrow's
   batch and shows it on `/reminders`; anything the owner does not cancel sends
   at the scheduled time. This keeps automation's leverage for a shop that never
   logs in, while leaving a human able to stop a mistake. A shop that ignores
   the preview still gets the benefit — which is the entire point.

2. **A planned reminder is a `reminder_logs` row, not a separate table.** Rows
   are created at planning time with `status = 'planned'` and a `batch_id`. The
   dispatcher flips them to `queued`; a cancellation flips them to `cancelled`.
   One timeline, one history, no reconciliation between a "plan" table and a
   "sent" table that can disagree. `reminder_batches` exists only to hold what
   the run itself did (when, how many, why it stopped).

3. **Per-customer cooldown, default 7 days.** A customer cannot be *auto*-
   reminded again within `reminder_cooldown_days`, however often the schedule
   runs. Without it, a daily run messages the same person every morning until
   they pay, which is harassment with a cron entry. The owner's manual tap on
   `/reminders` is **not** blocked by the cooldown — a human choosing to chase
   someone again is a different act from a machine doing it.

4. **Per-tenant daily cap, default 25.** Every automated message bills the
   platform, not the shop. The cap bounds worst-case spend, and a tenant with a
   1,000-customer book cannot drain the account overnight. When a run hits the
   cap it stops, records `stopped_reason = 'daily_cap'`, and the remainder waits
   for tomorrow — highest debt first, so the cap always spends on the biggest
   money. Metering is recorded per tenant per day, which is also exactly what a
   future billing decision would need.

5. **Quiet hours are global and non-negotiable.** Sends only leave between
   09:00 and 20:00 in the tenant's timezone (app timezone for now — VyaparBook is
   single-region). A tenant may choose *when* inside that window; they may not
   choose to message at 3am. The dispatcher re-checks at send time, so a backed-up
   queue cannot deliver a 09:00 batch at midnight.

6. **Automation is per-tenant opt-in, default off.** A shop must not discover
   the feature by having it message their customers. `reminder_auto_enabled`
   defaults false, and the settings form states plainly that messages will be
   sent without further approval.

7. **The planner is idempotent per tenant per day.** Cron double-fires, retries
   and manual invocations all happen. A unique `(business_id, scheduled_for)` on
   `reminder_batches` means a second plan for the same day is a no-op rather
   than a second set of messages.

## Flow

```
06:00 daily   reminders:plan
  for each tenant with reminder_auto_enabled:
    overdue (ReminderService)
      − opted out / no phone / bad phone      (already excluded as non-sendable)
      − reminded within cooldown_days
      → take daily_cap, biggest debt first
      → reminder_batches row + reminder_logs rows (status=planned)

every 15 min  reminders:dispatch
  for each batch whose send time has arrived, today, not cancelled:
    if driver !== cloud_api        → skip, record why
    if outside quiet hours         → skip, record why
    for each planned row: → status=queued + dispatch SendReminderJob
                             (4b then owns delivery, status and STOP)
```

### Schema

| Change | Why |
|---|---|
| `businesses.reminder_auto_enabled` bool default false | Decision 6. |
| `businesses.reminder_send_at` time default `10:00` | When inside quiet hours. |
| `businesses.reminder_cooldown_days` smallint default 7 | Decision 3. |
| `businesses.reminder_daily_cap` smallint default 25 | Decision 4. |
| `reminder_batches` | id, business_id, scheduled_for (date), status, planned_count, sent_count, stopped_reason, timestamps. Unique `(business_id, scheduled_for)` — Decision 7. RLS + app scope. |
| `reminder_logs.batch_id` nullable FK | Ties a planned row to its run; null for manual 4a/4b sends. |
| `reminder_logs.status` gains `planned`, `cancelled`, `skipped` | Decision 2. |

## Out of scope

Escalating dunning ladders (a cooldown, not stages), email/WhatsApp delivery of
the preview digest itself, per-tenant timezones, plan-based entitlement for
automation, and billing the shop for message spend — 4c meters, it does not
charge.

## Error handling / edge cases

| Case | Behaviour |
|---|---|
| Planner runs twice for one day | Second run is a no-op (unique constraint). |
| Owner cancels a planned row | `status = 'cancelled'`; dispatcher skips it; it stays visible as history. |
| Owner cancels the whole batch | Batch `status = 'cancelled'`; no row dispatches. |
| Customer pays after planning, before sending | Dispatcher re-checks outstanding; if now under the threshold the row is `skipped`, not sent. Nobody is chased for money they have paid. |
| Customer opts out after planning | 4b's job already re-checks and aborts; the row records `opted_out`. |
| Driver still `log` | Batch is planned and previewed but never dispatched; `stopped_reason = 'transport_disabled'` makes the reason visible rather than silent. |
| Cap reached mid-run | Stop, record `daily_cap`; remainder is re-planned tomorrow. |
| Dispatcher runs outside quiet hours | Skips, records `quiet_hours`; retries on the next tick inside the window. |
| Tenant disables automation mid-day | Dispatcher re-checks the flag; planned rows are skipped. |

## Testing

- **Unit** — `ReminderPlannerTest`: cooldown excludes a recently-reminded
  customer but not a manually-reminded-long-ago one; cap truncates biggest-debt
  first; non-sendable customers never planned; idempotent per day; tenant
  isolation.
- **Feature** — `ReminderDispatcherTest`: quiet hours block and then release;
  `log` driver blocks with the reason recorded; cancelled batch/row never sends;
  a customer who paid in between is skipped, not sent; disabling automation
  mid-day stops it.
- **Feature** — `RemindersTest` additions: preview renders, cancel works,
  settings form saves, and **manual sending is unaffected by the cooldown**.
- **Regression** — with automation off (the default) nothing about 4a/4b changes.

## Ops note

The scheduler needs `php artisan schedule:run` on cron and a running queue
worker. Neither exists in this deployment today — without them 4c plans nothing
and sends nothing, which is safe but silent. Deployment guidance belongs with
whoever operates the box; this spec only flags that automation has an
infrastructure prerequisite beyond code.

## Traceability

- 4b spec "out of scope (deferred to a scheduling phase)" → this phase, item by
  item: scheduling, quiet hours, rate limits, per-tenant opt-in, cost metering.
- PRD §18 Phase 2 "automated WhatsApp reminders" → fully delivered once this
  ships and the runbook is followed.
- PRD §13 DPDP → opt-out still authoritative at every stage; cooldown and cap
  are additional restraint, never a relaxation.
