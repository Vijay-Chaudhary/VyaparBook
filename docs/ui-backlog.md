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
