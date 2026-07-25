# WhatsApp Payment Reminders — Phase 4a Implementation Plan

**Goal:** Give the owner a reviewed **overdue list** at `/reminders` and a one-tap
`wa.me` deep link that opens WhatsApp with a prefilled reminder in the customer's
language, logging the intent. No Meta API and no unattended sending — 4b swaps
only the transport onto this foundation.

**Architecture:** Blade, online-only, owner-only, via `ResolvesOwnedTenant` —
identical to `ExpenseController`/`SupplierController`. A read-only
`ReminderService` computes the overdue list from `KhataService`; a pure
`ReminderMessage` composes the text + link; `reminder_logs` records intent. Money
is bcmath scale-2 decimal strings throughout.

**Tech Stack:** PHP 8.3 / Laravel 11, PostgreSQL (RLS), Blade, Pest. Reuses
`App\Support\Inr`, `App\Services\KhataService`, `ResolvesOwnedTenant`.

**Spec:** `docs/superpowers/specs/2026-07-25-whatsapp-payment-reminders-phase-4a-design.md`

---

## Before you start

- Branch off current `master` (Phase 3 merged as `0acd8a9`):

```bash
git checkout master && git pull
git checkout -b feat/whatsapp-reminders-phase-4a
```

- Local services (Postgres/PgBouncer/Redis) must be running; if the suite cannot
  connect, ask the user to start them (WSL sudo — only the user can).
- Record the green baseline: `cd backend && php artisan test` → **529 passed**.

### Conventions used throughout (read once)

- **App root is `backend/`.** All paths below are relative to it.
- Migrations run on the privileged `pgsql_migrate` connection; every tenant table
  gets `ENABLE` + `FORCE ROW LEVEL SECURITY` and an `_isolation` policy keyed on
  `current_setting('app.current_tenant')`. Copy the `expenses` migration.
- Every service query **also** carries an explicit `->where('business_id', …)` —
  defense in depth, never one layer alone.
- `created_by` is never fillable; it is stamped from `app('tenant.user_id')`.
- Money is a scale-2 decimal string, compared with `bccomp`, never cast to float.
- Tests write fixtures on `pgsql_migrate` to bypass RLS, then read through the
  tenant pin.

### Scope decisions locked from the spec (do not re-litigate)

- `wa.me` link only in 4a; no Cloud API, no webhook, no scheduler, no queue job.
- Owner reviews and taps; nothing sends unattended.
- Overdue = `outstanding ≥ reminder_min_outstanding` AND
  `days_since_last_payment ≥ reminder_min_days`. Defaults `500.00` / `30`.
- Customers with no/invalid phone and opted-out customers are **listed but not
  sendable** — never silently filtered.
- `reminder_logs` records **intent**, not delivery. It is never an input to
  outstanding.
- New `customers.reminder_opt_out_at` stays **server-side**: `KhataController`
  whitelists API fields explicitly, so do not add it there — the offline sync
  payload must not change in this phase.

---

## File structure

**Create:**
- `database/migrations/*_create_reminder_logs_table.php`
- `database/migrations/*_add_reminder_settings_to_businesses.php`
- `database/migrations/*_add_reminder_opt_out_to_customers.php`
- `app/Models/ReminderLog.php`
- `app/Reminders/OverdueCustomer.php` — readonly VO
- `app/Reminders/ReminderMessage.php` — phone normalisation + text + `wa.me` URL
- `app/Services/ReminderService.php`
- `app/Http/Controllers/Web/ReminderController.php`
- `resources/views/reminders/index.blade.php`
- `lang/en/reminders.php`, `lang/hi/reminders.php`
- `tests/Unit/ReminderMessageTest.php`, `tests/Unit/ReminderServiceTest.php`
- `tests/Feature/Web/RemindersTest.php`

**Modify:**
- `app/Models/Customer.php` — cast `reminder_opt_out_at`
- `app/Models/Business.php` — cast/fillable for the two settings
- `app/Export/TenantEraser.php`, `app/Export/TenantExporter.php` — register
  `reminder_logs`
- `routes/web.php` — the four routes in the owner group
- `resources/views/reports/partials/tiles.blade.php` *or* the dashboard header —
  a link through to `/reminders` (owner discovery)
- `docs/ui-backlog.md` — `F-04`

---

## Task 1: Schema — `reminder_logs`, settings, opt-out

- [ ] **Step 1: `reminder_logs` migration**

Copy the `expenses` migration's RLS block verbatim, changing the table name.
Columns: `id` uuid pk; `business_id` fk→businesses cascade; `customer_id`
fk→customers cascade; `channel` string(20) (`wa_link` in 4a, `cloud_api` in 4b);
`amount_at_send` decimal(12,2) (what they owed when reminded — the log must not
re-derive later); `locale` string(5); `phone_e164` string(20) nullable;
`created_by` foreignId→users; `timestamps`. Index `['business_id','customer_id','created_at']`
for the "already reminded today" check. **No** `version`/`sync_seq` — online-only,
never enters offline sync. No `uuid` idempotency column: a deliberate re-send is
legitimate (spec §Error handling).

- [ ] **Step 2: settings + opt-out migrations**

`businesses`: `reminder_min_outstanding` decimal(12,2) default `'500.00'`,
`reminder_min_days` smallint default `30`. `customers`: `reminder_opt_out_at`
timestamp nullable. Both plain `Schema::connection('pgsql_migrate')->table(...)`;
`businesses` is platform-level and needs no policy change, `customers` already
has its own.

- [ ] **Step 3: models**

`ReminderLog` — `BelongsToTenant`, `HasUuids`; `$fillable` excludes `created_by`.
Cast `amount_at_send` decimal:2. Add casts to `Customer` (`reminder_opt_out_at`
→ datetime) and `Business` (`reminder_min_outstanding` decimal:2,
`reminder_min_days` integer).

- [ ] **Step 4: register for DPDP**

Add `'reminder_logs'` to `TenantEraser::TABLES` **before** `customers` (FK order:
children first) and to `TenantExporter`'s list. Run the existing DPDP tests.

- [ ] **Step 5: migrate + commit**

```bash
php artisan migrate
git add database/migrations app/Models app/Export
git commit -m "feat: add reminder_logs, reminder thresholds and customer opt-out"
```

---

## Task 2: `ReminderMessage` — phone normalisation + composition (TDD, no DB)

This is pure logic; test it first and hardest, because a malformed link is
invisible until a customer gets a message meant for someone else.

- [ ] **Step 1: write `tests/Unit/ReminderMessageTest.php` first**

Cover: bare 10-digit `9876543210` → `919876543210`; already-prefixed `+91 98765 43210`
and `0091-9876543210`; embedded spaces/dashes/parens stripped; too short (`98765`),
too long (>15), letters, and `null` → **not normalisable** (returns null, never a
broken link); an already-`91`-prefixed 12-digit number is not double-prefixed.
Then: the URL is `https://wa.me/919876543210?text=…` with the text
percent-encoded; the message contains the shop name, the formatted amount via
`Inr::format`, and renders differently under `en` vs `hi`.

- [ ] **Step 2: run it and watch it fail** — `./vendor/bin/pest tests/Unit/ReminderMessageTest.php`

- [ ] **Step 3: implement `app/Reminders/ReminderMessage.php`**

```php
/** Digits-only E.164 for India; null when the number cannot be trusted. */
public static function normalisePhone(?string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
    $digits = ltrim($digits, '0');                 // 0091… / 09876…
    if (strlen($digits) === 10) {
        $digits = '91'.$digits;                    // bare Indian mobile
    }

    return preg_match('/^[1-9][0-9]{9,14}$/', $digits) === 1 ? $digits : null;
}
```

Text comes from `lang/{en,hi}/reminders.php` via `trans(..., locale: $locale)` —
the **customer-facing** locale, not the owner's UI locale. Keep the whole
composition in this class: 4b reuses it verbatim.

- [ ] **Step 4: run it and watch it pass. Commit.**

---

## Task 3: `OverdueCustomer` VO + `ReminderService` (TDD)

- [ ] **Step 1: the VO** — `name`, `village`, `phone` (raw), `phoneE164` (?string),
  `outstandingRupees`, `daysOverdue` (int), `lastPaymentOn` (?string),
  `sendable` (bool), `blockedReason` (?string: `no_phone`|`bad_phone`|`opted_out`).

- [ ] **Step 2: write `tests/Unit/ReminderServiceTest.php` first**

Boundaries matter — assert **at** the threshold, not comfortably past it:
outstanding exactly `500.00` with exactly `30` days is included; `499.99` or
`29` days is not. Plus: opted-out customer is **listed but `sendable === false`**;
no-phone likewise with `blockedReason === 'no_phone'`; negative outstanding
(advance) excluded entirely; never-paid customer counts `daysOverdue` from their
first sale; another tenant's overdue customer never appears; ordering is by
outstanding descending.

- [ ] **Step 3: implement `app/Services/ReminderService.php`**

Read the two thresholds off the pinned `Business`. Load non-archived customers
with their last payment date in one query (`leftJoin` a grouped
`max(payment_date)` sub-select on `payments`, plus a `min(sale_date)` sub-select
for the never-paid case) — **not** N+1 per customer. Outstanding comes from
`KhataService::outstandingFor()` per customer, matching how the dashboard already
does it; if that proves slow on a large book, note it rather than optimising
speculatively. Compare money with `bccomp`, never `<`.

- [ ] **Step 4: run, pass, commit.**

---

## Task 4: Controller, routes, view, translations (TDD)

- [ ] **Step 1: write `tests/Feature/Web/RemindersTest.php` first**

`access →` guest redirected to login; a user owning no business bounced to `/app`;
an owner asking for a business they do not own refused.
`render →` the overdue list shows an over-threshold customer and omits an
under-threshold one; a no-phone customer renders its reason and no send control.
`send →` POST writes exactly **one** `reminder_logs` row with the right
`amount_at_send` and `channel = 'wa_link'`, and redirects to a `wa.me` URL
containing the normalised number; posting another tenant's customer id 404s;
posting for an opted-out customer is refused (no log row written).
`opt-out →` toggles `reminder_opt_out_at` and makes the row non-sendable.

- [ ] **Step 2: implement the controller**

`use ResolvesOwnedTenant;` and follow `ExpenseController` exactly:
`ownedBusinessId($request->query('business'))` → redirect to `app` when null →
`runInTenant(...)`. Resolve `{customer}` **inside** the tenant-pinned closure,
never via implicit route-model binding (no tenant is pinned during route
resolution — the existing controllers all note this).

`store` writes the log then `redirect()->away($url)`. Guard: refuse when the
customer is opted out or the phone is not normalisable — the UI disables it, but
the server must not rely on the UI.

- [ ] **Step 3: routes** — inside the same owner group as `expenses`, with a
  comment block matching the house style:

```php
Route::get('reminders', [ReminderController::class, 'index'])->name('reminders');
Route::post('reminders/{customer}', [ReminderController::class, 'send'])->name('reminders.send');
Route::post('reminders/{customer}/opt-out', [ReminderController::class, 'optOut'])->name('reminders.opt_out');
Route::post('reminders/{customer}/opt-in', [ReminderController::class, 'optIn'])->name('reminders.opt_in');
```

- [ ] **Step 4: view + translations**

`resources/views/reminders/index.blade.php` extending `layouts.app`, matching
`expenses/index.blade.php`: header, a short explainer that this opens the owner's
own WhatsApp, the threshold summary, then the table (customer, village, phone,
outstanding, days overdue, action). Non-sendable rows show the reason instead of
the button. Empty state names the current thresholds. Full `en` + `hi` keys —
including the **customer-facing** message body, which is what actually gets sent.

- [ ] **Step 5: link it from the dashboard** so the owner can find it — a link
  near the outstanding tile through to `route('reminders')`.

- [ ] **Step 6: run** `php artisan view:clear && ./vendor/bin/pest tests/Feature/Web/RemindersTest.php`, then commit.

---

## Task 5: Full-suite green + wrap-up

- [ ] **Step 1: `php artisan test`** — expect 529 + the new cases, no regressions.
- [ ] **Step 2: manual check** — `/reminders` as the demo owner; confirm the link
  opens WhatsApp with the right number and prefilled text, and that a no-phone
  and an opted-out customer both render as non-sendable.
- [ ] **Step 3: `docs/ui-backlog.md`** — add `F-04`.
- [ ] **Step 4: commit, then finish the branch** (PR → squash-merge, per the
  Phase 3 flow; `gh api` REST, since `gh pr` subcommands fail on this repo).

---

## Self-review notes (traceability to the spec)

- Decision 1 (4a/4b split) → `ReminderMessage` owns text+link so 4b swaps only
  transport; `reminder_logs.channel` already distinguishes them.
- Decision 2 (owner reviews) → no scheduler, no queue job anywhere in this plan.
- Decision 3 (platform-number semantics) → message names the shop, not the sender.
- Decision 4 (log intent) → `amount_at_send` frozen at send time; never an input
  to outstanding.
- Decision 5 (thresholds) → two columns on `businesses`, tested at their exact
  boundaries (Task 3 Step 2).
- Decision 6 (no phone → shown) → `blockedReason`, asserted in Task 3 and Task 4.
- Decision 7 (customer consent) → `reminder_opt_out_at` + opt-out/opt-in routes;
  server-side enforcement in `store`, not just the UI.
- Multi-tenant isolation → RLS + explicit `business_id`; cross-tenant tests in
  both Task 3 and Task 4.
- PRD §13 DPDP → `reminder_logs` registered in eraser and exporter (Task 1 Step 4).
