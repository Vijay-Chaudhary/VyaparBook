# UI Backlog

Lightweight tracking for frontend work — bugs, features, and UI polish across the
Blade shell and the React islands under `/app/*`. This doc is the fast, in-repo
capture point; anything that needs assignment, discussion, or a milestone should
graduate to a **GitHub issue** on `Vijay-Chaudhary/VyaparBook` under the matching
label (`bug`, `feature`, `ui`).

## Conventions

- **ID** — `B-01` (bug), `F-01` (feature), `U-01` (ui polish). Never reuse an ID.
- **Status** — `todo` · `in-progress` · `blocked` · `done`. Keep `done` rows for one
  release, then prune.
- **Area** — where it lives, e.g. `khata`, `stock`, `onboarding`, `billing`,
  `platform-console`, `offline/sync`, `shell`.
- **Issue** — link the GitHub issue number once one is opened (`#12`), else `—`.
- Newest items go at the top of each table. One line per item; put detail
  (repro steps, screenshots, acceptance criteria) in the linked GitHub issue.

---

## Bugs

Label: `bug`

| ID | Status | Area | Summary | Issue |
|----|--------|------|---------|-------|
| B-01 | done | shell/home | `Home.jsx:24` hardcoded the Hindi greeting `नमस्ते, ${userName}` instead of `t()` — showed Hindi even in English mode. Fixed: added a `greeting` key to both locales (`en: 'Namaste'`, `hi: 'नमस्ते'`) and render `` `${t('greeting')}, ${userName}` ``. | — |

---

## Features

Label: `feature`

| ID | Status | Area | Summary | Issue |
|----|--------|------|---------|-------|
| F-07 | done | platform-console | WhatsApp credentials in the console — going live previously needed shell access and a redeploy, and the Phase 4b smoke test (which 4c requires before automation may be enabled) could not be run at all. New superadmin-only `/admin/console/whatsapp`: transport driver, API version, phone number ID, template name and the three secrets, plus a **Test connection** action that performs a REAL send to a number you choose and shows Meta's response verbatim. Secrets use Laravel's `encrypted` cast and are **write-only** — never rendered back, and a blank field keeps the stored value instead of wiping a working credential. New `WhatsAppConfig` is the single answer to "what is the live configuration": a non-empty console value wins, else `.env`, resolved **per field** so a half-filled row cannot blank out env, and every field is labelled with which source is in force. Saving and testing are `PlatformAudit`-logged (that secrets changed, never their values). Architecture unchanged: still one platform number, no per-tenant credentials (4a Decision 3). See spec `docs/superpowers/specs/2026-07-25-whatsapp-console-credentials-design.md`. | — |
| F-06 | done (opt-in, dark) | reports/reminders | Scheduled reminders (Phase 4c) — the automation 4a/4b deliberately deferred. `reminders:plan` (daily 06:00) builds a per-tenant batch from `ReminderService`'s same overdue definition, filtered by a **per-customer cooldown** (default 7d, counting **automated** history only — a manual tap is a human decision and never blocks the machine's restraint) and truncated to a **per-tenant daily cap** (default 25, biggest debts first, `stopped_reason = daily_cap`). A planned reminder **is** a `reminder_logs` row (`status = planned`, `batch_id`), not a parallel table, so plan and send history can never disagree; `reminder_batches` records only what the run did. `reminders:dispatch` (every 15m) **re-checks everything** before releasing: automation still on, transport is `cloud_api`, inside global quiet hours 09:00–20:00, tenant's send time reached, customer hasn't opted out, and **outstanding re-derived** so nobody is chased for money they already paid. Owner sees a "Scheduled to send" card and can cancel any row or leave it to go. Unique `(business_id, scheduled_for)` makes planning idempotent against double cron fires. `reminder_logs.created_by` became nullable — the scheduler has no human author and stamping the owner's id would misattribute it. **Automation is per-tenant opt-in, default off, and must not be enabled until the 4b smoke test has run against the real Meta API** (a scheduler turns a wrong assumption into a whole book of bad messages). Adds the app's first scheduler; needs `schedule:run` on cron **and** a queue worker, or reminders are planned and never sent. See spec `docs/superpowers/specs/2026-07-25-scheduled-reminders-phase-4c-design.md` and plan `docs/superpowers/plans/2026-07-25-scheduled-reminders-phase-4c.md`. | — |
| F-05 | done (dark) | reports/reminders | WhatsApp Cloud API transport (Phase 4b) — swaps how the 4a reminder is delivered, not what it says. New `WhatsAppSender` driver interface: `LogSender` (**the default — sends nothing**) and `CloudApiSender` (Meta Graph API, approved template + positional params, since Meta rejects free-form business-initiated messages). Sending is queued via the app's first job, `SendReminderJob`, which re-checks opt-out before sending and only acts on a row still `queued`, so a replay cannot double-send. New delivery columns on `reminder_logs` (`status`, `status_at`, `provider_message_id`, `error_code`, `error_message`); status shown per row on the list. New signature-verified webhook at `/webhooks/whatsapp` (HMAC SHA-256 of the raw body, `hash_equals`; bad signature = 403 writing nothing) handling delivery callbacks — status only ever moves forward, so out-of-order callbacks are safe — and inbound STOP. **Inbound STOP opts the person out across EVERY tenant holding that number**: replies land on one platform number and the person cannot say which shop they mean, so honouring it per-tenant would keep the others messaging them. That is the app's only cross-tenant write — privileged connection, one transaction, `PlatformAudit`-logged. **Ships dark:** `WHATSAPP_DRIVER=log` by default, and a test asserts 4a's `wa.me` behaviour is unchanged under it. **Not verified against the real Meta API** — every test uses `Http::fake`, so the first live send needs the smoke test in the spec's runbook. See spec `docs/superpowers/specs/2026-07-25-whatsapp-cloud-api-phase-4b-design.md` and plan `docs/superpowers/plans/2026-07-25-whatsapp-cloud-api-phase-4b.md`. | — |
| F-04 | done | reports/reminders | WhatsApp payment reminders (Phase 4a) — the action the outstanding figure implies. New owner-only `/reminders` review list: `ReminderService` picks customers who owe ≥ `businesses.reminder_min_outstanding` **and** have not paid in ≥ `reminder_min_days` (defaults ₹500 / 30 days, per-shop), biggest debt first; outstanding still comes from `KhataService`, never recomputed. Tapping Remind logs intent to the new `reminder_logs` table (RLS + app scope, registered in DPDP export/erase) and redirects to a `wa.me` deep link prefilled by `ReminderMessage` in the **shop's** language — so the message leaves the owner's own WhatsApp. Customers who cannot be reached (no phone / unusable number / opted out) are listed with the reason, never filtered out. New `customers.reminder_opt_out_at` is the customer-side DPDP mechanism (`consents` is user-level and does not cover a shop's customers); kept out of the API whitelist so offline sync is unchanged. Opt-out and phone validity are re-checked server-side. Phase 4b swaps only the transport (Cloud API, platform number) onto the same message and log. See spec `docs/superpowers/specs/2026-07-25-whatsapp-payment-reminders-phase-4a-design.md` and plan `docs/superpowers/plans/2026-07-25-whatsapp-payment-reminders-phase-4a.md`. | — |
| F-03 | done | reports/cash | Cash flow (Phase 3) on the owner dashboard — the cash view the accrual P&L was missing. New derived-only `CashFlowService` (no new tables): cash-**in** = customer payments (reversals self-net), cash-**out** = supplier payments + operating expenses (both non-archived), 12-month In/Out/Net trend plus a running **cash position** seeded from a pre-year opening sum. Sales/purchases (accrual) and subscription payments (SaaS billing) are excluded; `mode` is instrument-agnostic. The position is labelled "recorded in VyaparBook — not a bank balance" (no opening-cash table). New Cash Position tile (loss-aware danger colour), `partials/cash.blade.php` table + net-cash chart (negatives render red), en/hi keys; additive fields on `DashboardReport` (nothing renamed), all bcmath. Running position and the selected-month headline come from one walk so they cannot drift. See spec `docs/superpowers/specs/2026-07-25-owner-cash-flow-phase-3-design.md` and plan `docs/superpowers/plans/2026-07-25-owner-cash-flow-phase-3.md`. | — |
| F-02 | done | reports/expenses | Operating expenses & Net Profit (Phase 1). New owner-only Blade `/expenses` CRUD (record/edit/archive expenses by category — rent, salaries, electricity, transport, maintenance, other; operating-expenses only), and the dashboard P&L it unlocks: Sales → Est. product cost → Est. Gross Profit → Operating Expenses → **Net Profit** + net margin %, a by-category breakdown, a monthly net-profit chart, and Expenses/Net-profit trend columns (loss-aware, danger colour). New `expenses` table (RLS + app scope, soft-delete, registered in DPDP export/erase); net profit computed in `DashboardReportService`, all bcmath. See spec `docs/superpowers/specs/2026-07-22-owner-expenses-net-profit-phase-1-design.md` and plan `docs/superpowers/plans/2026-07-22-owner-expenses-net-profit-phase-1.md`. | — |
| F-01 | done | reports | Owner management dashboard (Phase 0) at `GET /reports/dashboard` — Blade, online-only, owner-only. Sales (today/month), customer outstanding (khata parity), production, low-stock, product-wise performance, and 12-month sales/production trends with server-rendered inline-SVG charts. All figures computed from existing data (no new tables), bcmath decimal strings throughout, tenant-pinned (RLS + app scope). Owner link added to `Home.jsx`. See spec `docs/superpowers/specs/2026-07-22-owner-reporting-dashboard-phase-0-design.md` and plan `docs/superpowers/plans/2026-07-22-owner-reporting-dashboard-phase-0.md`. | — |

---

## UI polish

Label: `ui`

| ID | Status | Area | Summary | Issue |
|----|--------|------|---------|-------|
| U-01 | done | khata/forms | New-customer form: the "Opening balance" hint duplicated its own label verbatim in English (Hindi differentiated `पुराना बकाया` vs `शुरुआती बकाया`, but both English `opening_balance` and `opening` were `'Opening balance'`). Fixed: English `opening` hint is now `'Amount owed before you started using VyaparBook'` (`i18n.js:157`). | — |

---

## Related (backend)

Not UI, but surfaced during the same app-run discovery pass — tracked here for completeness:

- **`default_language_to_en` migration failed** with `must be owner of table businesses` (bare `DB::statement` ran as the non-owner app role). Fixed in `7d6f311`; tracked as [#2](https://github.com/Vijay-Chaudhary/VyaparBook/issues/2) (closed).
- **`DemoDataSeeder` seeded stock out-movements unsigned** (`+12.000` instead of `-12.000`), inflating on-hand to 112 vs 88 — stock qty is stored signed (`in +`, `out −`). Seed-data only; app logic is correct. Fixed in `e45f211`; tracked as [#3](https://github.com/Vijay-Chaudhary/VyaparBook/issues/3) (closed).
