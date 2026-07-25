# Scheduled Reminders — Phase 4c Implementation Plan

**Goal:** Plan tomorrow's reminders on a schedule, show the owner a preview they
can cancel from, and send what remains inside quiet hours, under a per-customer
cooldown and a per-tenant daily cap.

**Architecture:** `ReminderPlanner` (builds a batch of `planned` `reminder_logs`
rows) + `ReminderDispatcher` (flips them to `queued` and hands off to 4b's
`SendReminderJob`), driven by two artisan commands on Laravel's scheduler.

**Spec:** `docs/superpowers/specs/2026-07-25-scheduled-reminders-phase-4c-design.md`

---

## Before you start

```bash
git checkout master && git pull
git checkout -b feat/scheduled-reminders-phase-4c
```

- Baseline `php artisan test` → **589 passed**.
- Automation is off by default and the dispatcher refuses unless the driver is
  `cloud_api`; nothing in this plan can send a real message.

### Scope decisions locked from the spec (do not re-litigate)

- Preview + auto-send unless cancelled. Not fully unattended, not arm-each-run.
- A planned reminder **is** a `reminder_logs` row (`status = 'planned'`,
  `batch_id`), not a parallel table.
- Cooldown (default 7d) applies to **automated** sends only — a manual tap is a
  human decision and stays unblocked.
- Daily cap (default 25), biggest debt first; hitting it records `daily_cap`.
- Quiet hours 09:00–20:00, re-checked at dispatch, not just at planning.
- Per-tenant opt-in, default off. Planner idempotent per tenant per day.

---

## Task 1: Schema + settings

- [x] **Step 1: migration** — `businesses`: `reminder_auto_enabled` bool default
  false, `reminder_send_at` time default `'10:00'`, `reminder_cooldown_days`
  smallint default 7, `reminder_daily_cap` smallint default 25.

- [x] **Step 2: migration** — `reminder_batches` (uuid id, business_id FK,
  `scheduled_for` date, `status` string(12) default `'planned'`, `planned_count`
  int, `sent_count` int default 0, `stopped_reason` string(24) nullable,
  timestamps). **Unique `(business_id, scheduled_for)`** — the idempotency
  guarantee. RLS enable + force + isolation policy, copied from `expenses`.

- [x] **Step 3: migration** — `reminder_logs.batch_id` nullable FK →
  `reminder_batches`, indexed. Null for manual sends.

- [x] **Step 4: models** — `ReminderBatch`; casts on `Business` for the four new
  settings; `batch_id` fillable on `ReminderLog`.

- [x] **Step 5: DPDP** — `reminder_batches` into `TenantEraser` (before
  `reminder_logs`, FK order) and `TenantExporter`. Run the DPDP tests.

- [x] **Step 6: migrate, run suite, commit.**

---

## Task 2: `ReminderPlanner` (TDD)

- [ ] **Step 1: write `tests/Unit/ReminderPlannerTest.php` first**

- A sendable overdue customer is planned; a non-sendable one (no phone, opted
  out) never is.
- A customer auto-reminded 3 days ago is **excluded** at a 7-day cooldown;
  one reminded 8 days ago is included.
- The cap truncates to the **biggest debts** — seed 5 customers, cap 3, assert
  which three and that `stopped_reason = 'daily_cap'`.
- Under the cap, `stopped_reason` is null.
- Running twice for the same day is a no-op: one batch, no duplicate rows.
- A tenant with automation off is not planned at all.
- Tenant isolation.

- [ ] **Step 2: implement `app/Services/ReminderPlanner.php`**

Reuses `ReminderService::overdue()` — the definition of "overdue" must not fork.
Filters `sendable`, applies the cooldown from the last **automated**
(`batch_id is not null`) reminder, sorts by outstanding desc (already sorted),
takes the cap, writes the batch + `planned` rows in one transaction.

- [ ] **Step 3: run, pass, commit.**

---

## Task 3: `ReminderDispatcher` (TDD)

- [ ] **Step 1: write `tests/Feature/ReminderDispatcherTest.php` first**

- Inside quiet hours with `cloud_api`: planned rows become `queued` and
  `SendReminderJob` is pushed (`Queue::fake()`), `sent_count` updated.
- Outside quiet hours: nothing dispatched, `stopped_reason = 'quiet_hours'`.
- Driver `log`: nothing dispatched, `stopped_reason = 'transport_disabled'`.
- A cancelled row is skipped; a cancelled batch dispatches nothing.
- A customer who **paid** after planning is `skipped`, not sent — assert no job
  pushed for them.
- Automation disabled after planning: nothing dispatches.
- Before `reminder_send_at`: nothing dispatches yet.

- [ ] **Step 2: implement `app/Services/ReminderDispatcher.php`**

Re-checks, in this order: automation still on → driver is `cloud_api` → inside
quiet hours → send time reached. Then per row: outstanding still over the
threshold (via `KhataService`) → flip to `queued` → dispatch. Everything it
refuses to do is recorded, never silent.

- [ ] **Step 3: run, pass, commit.**

---

## Task 4: Commands + scheduler

- [ ] **Step 1: `reminders:plan`** and **`reminders:dispatch`** artisan commands
  — thin wrappers that iterate tenants and delegate; each prints a summary line
  per tenant so a cron log is readable.

- [ ] **Step 2: register in `bootstrap/app.php`** via `->withSchedule()`:
  `reminders:plan` daily at 06:00, `reminders:dispatch` every fifteen minutes.
  Both `withoutOverlapping()`. **This is the app's first scheduler** — add the
  comment explaining that cron + a queue worker are required, per the spec's ops
  note.

- [ ] **Step 3: command tests** — `reminders:plan` creates batches only for
  opted-in tenants and exits 0 with no tenants at all.

- [ ] **Step 4: run, pass, commit.**

---

## Task 5: Preview + settings UI

- [ ] **Step 1: extend `RemindersTest`** — the preview section lists tomorrow's
  planned customers; cancelling a row sets `cancelled` and it stops being
  dispatchable; cancelling the batch cancels all; the settings form saves the
  four fields and validates (`send_at` inside quiet hours, cap 1–200, cooldown
  0–90); **manual send is still unaffected by the cooldown**; another tenant's
  batch 404s.

- [ ] **Step 2: controller + view + `lang/{en,hi}`** — a "Scheduled for
  tomorrow" card above the overdue table, and a settings form. The form must
  state plainly that enabling means messages send without further approval.

- [ ] **Step 3: run, pass, commit.**

---

## Task 6: Full suite, docs, wrap-up

- [ ] **Step 1: `php artisan test`** — 589 baseline + new, no regressions.
- [ ] **Step 2: `docs/ui-backlog.md`** — `F-06`, noting automation is
  per-tenant opt-in AND blocked on the 4b smoke test.
- [ ] **Step 3: manual check** — **blocked**: needs cron, a queue worker and
  real credentials. Record as outstanding; do not tick.
- [ ] **Step 4: commit, PR, squash-merge** (`gh api` REST).

---

## Self-review notes (traceability to the spec)

- Precondition → dispatcher refuses unless `cloud_api`; Task 3 Step 1 asserts it.
- Decision 1 (preview, auto-send unless cancelled) → Task 5.
- Decision 2 (planned row is a log row) → Task 1 Step 3, Task 2 Step 2.
- Decision 3 (cooldown, manual unaffected) → Task 2 Step 1 + Task 5 Step 1.
- Decision 4 (cap, biggest debt first) → Task 2 Step 1's five-customer case.
- Decision 5 (quiet hours re-checked at dispatch) → Task 3.
- Decision 6 (opt-in default off) → Task 1 Step 1 default + Task 2 Step 1.
- Decision 7 (idempotent per day) → unique constraint + double-run test.
