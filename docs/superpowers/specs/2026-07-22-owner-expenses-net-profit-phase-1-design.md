# Owner Expenses & Net Profit — Phase 1 Design

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

**Date:** 2026-07-22
**Status:** Approved (design); ready for implementation plan
**Scope:** Phase 1 of the multi-phase "Management Dashboard" for business owners.
**Builds on:** `docs/superpowers/specs/2026-07-22-owner-reporting-dashboard-phase-0-design.md`

---

## Background

Phase 0 shipped an owner-only Blade dashboard (`/reports/dashboard`) that
aggregates existing data: sales, customer outstanding, production, low-stock,
product-wise performance, and monthly sales/production trends. It also added a
computable **Estimated Gross Profit** (sales − estimated product cost, from pack
cost prices), explicitly labelled "before operating expenses".

The one thing owners keep asking for that Phase 0 could not compute is **Net
Profit** — because operating expenses (rent, salaries, electricity, transport,
maintenance) have **no home in the schema**. Phase 0 deliberately hid every
Net-Profit / P&L block rather than show a misleading number.

Phase 1 adds the missing piece: an **Operating Expenses** module (record / edit
/ delete expenses by category) and the **P&L** it unlocks on the dashboard —
Gross Profit → minus Expenses → **Net Profit**, a net margin %, an
expenses-by-category breakdown, and a monthly net-profit trend + chart.

### Phase decomposition (for context)

| Phase | Adds | Lights up |
|-------|------|-----------|
| 0 (done) | Owner dashboard + read-only aggregation. No new tables. | Sales, Customer Outstanding, Production, Low-Stock, Product Performance, Est. Gross Profit, Sales/Production/Gross-Profit charts |
| **1 (this doc)** | `Expense` module (new table + owner entry screen) | Operating Expenses total + by-category, **Net Profit**, Net Margin %, Monthly Expenses + Net-Profit charts |
| 2 | `Supplier` + Raw-Material-Purchase (with cost) | Supplier Outstanding, Raw-Material cost, true Gross Profit (actual COGS), Cost/Kg, Stock Value |
| 3 | Cash Balance & full P&L tie-out | Opening Cash, derived Cash Balance |

---

## Decisions (locked)

These were settled during brainstorming and drive the design:

1. **Full expenses feature**, not a monthly lump sum: the owner records, edits,
   and deletes individual operating expenses, each with a category, amount,
   date, and optional note. The dashboard then shows a full P&L down to Net
   Profit with a per-category expense breakdown.
2. **Fixed category list + Other:** `rent, salaries, electricity, transport,
   maintenance, other`. A dropdown (bilingual labels), not free text — so the
   by-category breakdown is clean and reportable. `other` carries a free-text
   note.
3. **Operating expenses only.** Raw-material / stock purchases are **never**
   entered here. Product cost is already captured via `ProductPack` cost prices
   and subtracted in Est. Gross Profit; counting purchases again would
   double-subtract and understate profit. (Real purchase-side cost is Phase 2.)
4. **Surface:** Blade, server-rendered, **online-only**, **owner-only** — same
   genre as `/billing` and `/reports/dashboard`, resolved via
   `ResolvesOwnedTenant` and run tenant-pinned (RLS + app scope). Not offline:
   recording expenses is an occasional back-office task, not counter work
   (`docs/frontend-plan.md`: "anything a shopkeeper does while serving a customer
   is React + offline; everything else is Blade").
5. **Net Profit is estimated.** Gross Profit is estimated (pack cost prices, not
   audited COGS), so Net Profit inherits that. The dashboard keeps the
   "estimated / before-audited-cost" caption — this is a management estimate,
   not accounting.
6. **Owner-only entry** for Phase 1 (staff/accountant roles are a later PRD
   phase). **Manual entry only** — no recurring/auto-repeating expenses (YAGNI).
   **Soft-delete** (archive), like `customers`, for a small audit trail.

---

## What is computable in Phase 1

- **Operating Expenses** (total, by category, monthly trend) → from the new
  `expenses` table.
- **Net Profit** = Est. Gross Profit − Operating Expenses, per selected month
  and as a 12-month trend. May be **negative** (a loss) — handled explicitly.
- **Net Margin %** = Net Profit ÷ Sales × 100, one decimal; **0.0** when sales
  are 0 (no divide-by-zero), like the existing product-margin guard.

**Still out of scope (later phases):** true Gross Profit from actual COGS,
Supplier Outstanding, Stock Value, Cost/Kg (Phase 2); Cash Balance, Opening Cash
(Phase 3); CSV/PDF export; a caching layer.

---

## Architecture

Follows the existing owner-tool pattern exactly (`Web\BillingController`,
`Web\ReportController`): the web (session) guard carries a user but **no
tenant**, so the controller resolves the owned business from the caller's own
membership (`ResolvesOwnedTenant::ownedBusinessId`) and pins the tenant itself
(`runInTenant`) — never trusting a request-supplied business id.

### Data model — new `expenses` table

Tenant-owned, enforcing **RLS AND** the app-level `BelongsToTenant` scope
(defense in depth, per project rules).

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `business_id` | fk → businesses | RLS-scoped; `BelongsToTenant` |
| `uuid` | uuid, unique | idempotent create (double-submit safe) |
| `category` | varchar, checked | one of `rent, salaries, electricity, transport, maintenance, other` |
| `amount` | `numeric(12,2)` | bcmath decimal string; never float; `> 0` |
| `spent_on` | date | the day the expense belongs to (drives month/year grouping) |
| `note` | varchar(255), nullable | free text; expected for `other` |
| `created_by` | fk → users | stamped from `app('tenant.user_id')`, **not** request input |
| `archived_at` | timestamptz, nullable | soft-delete; archived rows excluded from all reads |
| `created_at`, `updated_at` | timestamps | |

**No** `version` / `sync_seq` columns or `HasVersion` / `HasSyncSequence` traits:
expenses are online-only Blade and never enter the offline sync surface (which
is deliberately limited to customer/sale/payment).

Migration provisions the table on the `pgsql_migrate` connection with an RLS
policy identical in shape to the other tenant tables, plus an index on
`(business_id, spent_on)` for the month/trend queries.

`App\Models\Expense`: `BelongsToTenant`, `HasUuids`; `$fillable` =
`['business_id', 'uuid', 'category', 'amount', 'spent_on', 'note']`
(`created_by` intentionally absent — stamped, not filled); casts
`amount → decimal:2`, `spent_on → date`, `archived_at → datetime`.

### Categories

A single source of truth: `App\Expenses\ExpenseCategory` (a small value
object / enum-like class) exposing the ordered list of valid category keys and a
validation helper. Blade + the validator + the dashboard breakdown all read from
it, so the list is defined once. Display labels live in `lang/{en,hi}/expenses.php`.

### Expense entry — the `/expenses` screen (Blade, owner-only)

| Route | Method | Purpose |
|-------|--------|---------|
| `expenses` | GET | Selected-month list + month total + "Add expense" form. Month/year picker reuses `ReportPeriod`. |
| `expenses` | POST | Create one expense (idempotent by `uuid`). |
| `expenses/{expense}` | PUT | Edit an owned, non-archived expense. |
| `expenses/{expense}` | DELETE | Archive an owned expense. |

- New `App\Http\Controllers\Web\ExpenseController` (`use ResolvesOwnedTenant`),
  registered inside the existing `auth` route group next to `reports/dashboard`.
  **Decision:** it follows the billing/reports owner-tool pattern
  (owner-resolved, tenant-pinned) and is reachable by any owner regardless of
  subscription state — like `/billing`, it is **not** behind the write plan-gate.
  Rationale: recording one's own operating expenses is harmless bookkeeping, and
  a lapsed/`read_only` owner still needs an accurate management view. (If a
  plan-gate is ever wanted for expense writes, it is a one-line middleware add.)
- **Route-model binding is owner-checked:** `{expense}` is resolved inside the
  tenant pin, and the controller re-verifies `business_id` matches the resolved
  owned business before update/delete — a guessed id from another tenant 404s /
  redirects, never mutates.
- Validation: `category` ∈ `ExpenseCategory::keys()`, `amount` numeric `gt:0`
  (stored as a scale-2 string via bcmath), `spent_on` a real date, `note`
  nullable ≤ 255 (required when `category = other`).
- Views: `resources/views/expenses/index.blade.php` (+ small partials for the
  add/edit form and the list table), styled with the existing `card` /
  `field-input` / `btn-primary` classes, matching billing/reports.

### Dashboard P&L integration

Extend `DashboardReportService` (no new service — expenses aggregation belongs
with the other dashboard aggregation, all tenant-pinned and set-based):

- `expensesForMonth(businessId, year, month): string` — Σ amount, scale 2.
- `expensesByCategory(businessId, year, month): list<{category, amountRupees}>` —
  grouped, ordered by the canonical category order, zero rows omitted.
- `expensesTrend(businessId, year): list<string>` — 12 scale-2 strings.
- `forMonth(...)` additionally computes, from the already-fetched gross-profit
  trend and a new expenses trend:
  - `expensesMonthRupees`
  - `netProfitMonthRupees` = `estGrossProfitMonthRupees − expensesMonthRupees`
    (may be negative)
  - `netProfitMarginPercent` = net ÷ sales × 100, one decimal, `'0.0'` when
    sales are 0
  - per-category breakdown for the selected month
  - each `TrendRow` gains `expensesRupees` and `netProfitRupees`
    (= that month's gross − that month's expenses)

New `DashboardReport` fields: `expensesMonthRupees`, `netProfitMonthRupees`,
`netProfitMarginPercent`, `expenseBreakdown`. `TrendRow` gains `expensesRupees`,
`netProfitRupees`.

Views (dashboard):
- A **P&L block** partial for the selected month:
  `Sales → − Est. product cost → = Est. Gross Profit → − Expenses → = Net Profit
  (margin %)`, with the estimated-cost caption. Net Profit shown in
  success/danger colour by sign (profit vs loss).
- An **expenses-by-category** mini-table (empty state when none).
- A new **Monthly net-profit** SVG chart (reusing `x-svg-bar-chart`; the
  component clamps negatives — see Error Handling) alongside the existing
  sales/gross-profit/production charts.
- New **Expenses** and **Net profit** columns in the monthly trend table.

### Navigation

An owner-only link to `/expenses` from the dashboard header (next to "Back to
app"), and a matching entry so owners can move between the dashboard and expense
entry. (No React `Home` change is required — expenses is discoverable from the
dashboard the owner already reaches.)

---

## Data flow

```
Owner → GET /expenses ─┐
                       ├─ ResolvesOwnedTenant::ownedBusinessId(request business)
                       │     → null ⇒ redirect /app
                       └─ runInTenant(businessId):           (RLS + app scope + owner)
                            Expense::where(business_id)->whereNull(archived_at)
                              ->whereMonth(spent_on)…  → list + total
Owner → POST/PUT/DELETE /expenses → runInTenant → create/update/archive (owned-checked)

Owner → GET /reports/dashboard → runInTenant → DashboardReportService::forMonth
   … existing aggregation …
   + expensesForMonth / expensesByCategory / expensesTrend
   → Net Profit = Est. Gross Profit − Expenses  → P&L block + charts + trend cols
```

All money is bcmath decimal strings at scale 2, summed with `bcadd/bcsub`; Net
Profit uses `bcsub` (never floats); margin uses `bcdiv`/`bccomp` with the
sales-is-zero guard.

---

## Error handling & edge cases

- **Loss (negative net profit):** fully supported. `bcsub` yields a negative
  string; the P&L and trend render it (danger colour), and the net-profit chart
  handles it — see below.
- **Net-profit chart with negative values:** `SvgBarChart` currently scales bar
  heights to the series max and renders a zero-height bar for non-positive
  values. For the net-profit series this means loss months render flat (height
  0). Phase 1 keeps that behaviour (a simple, honest "no positive profit bar")
  rather than introducing a zero-baseline diverging chart — the trend **table**
  carries the exact signed figure. This is called out so it is a decision, not a
  bug. (A signed chart is a later polish item.)
- **Zero sales:** margin is `'0.0'`, not a division error.
- **No expenses in the month:** expenses total `'0.00'`, breakdown empty (empty
  state shown), Net Profit == Est. Gross Profit.
- **Editing/deleting another tenant's expense:** blocked by the owner re-check
  inside the tenant pin; returns a redirect/404, never mutates.
- **Double-submit of the add form:** idempotent by `uuid` (a replayed uuid does
  not append a second row), mirroring `BillingController::storePayment`.
- **Out-of-range month/year in the picker:** clamped by `ReportPeriod`, as on
  the dashboard.

---

## Testing

TDD throughout (Pest), test data written on the privileged `pgsql_migrate`
connection and read back through the tenant-pinned service/controller, exactly
like the Phase 0 tests.

- **Unit — `ExpenseCategory`:** valid keys, ordering, `other` requires note rule.
- **Unit — `DashboardReportService`:** expenses total; by-category breakdown
  (ordering, zero categories omitted); expenses trend (12 rows); Net Profit for
  a month (profit case **and** a loss case where expenses > gross); net margin
  incl. the zero-sales guard; tenant isolation (a second business's expenses
  never leak in).
- **Feature — `/expenses` (owner-only):** guest → login; non-owner / other-owner
  → redirect; create persists and appears in the list; edit updates; delete
  archives (and drops from the list and from the dashboard total); another
  tenant's expense id cannot be edited/deleted; idempotent create.
- **Feature — dashboard render:** the P&L block shows Net Profit and margin; a
  seeded expense reduces Net Profit vs Est. Gross Profit; by-category table
  renders; trend gains the Expenses / Net-profit columns.
- Full suite stays green; the suite remains asset-independent (`withoutVite`).

---

## Files (indicative)

**Create**
- `database/migrations/xxxx_create_expenses_table.php`
- `app/Models/Expense.php`
- `app/Expenses/ExpenseCategory.php`
- `app/Http/Controllers/Web/ExpenseController.php`
- `resources/views/expenses/index.blade.php` (+ form/list partials)
- `resources/views/reports/partials/pnl.blade.php` (+ expenses-by-category, net-profit chart wiring)
- `lang/en/expenses.php`, `lang/hi/expenses.php`
- Tests: `tests/Unit/ExpenseCategoryTest.php`, additions to
  `tests/Unit/DashboardReportServiceTest.php`,
  `tests/Feature/Web/ExpensesTest.php`, additions to
  `tests/Feature/Web/ReportsDashboardTest.php`

**Modify**
- `app/Services/DashboardReportService.php` (expenses aggregation + net profit)
- `app/Reports/DashboardReport.php`, `app/Reports/TrendRow.php` (new fields)
- `resources/views/reports/dashboard.blade.php`,
  `resources/views/reports/partials/{trend,charts}.blade.php`
- `routes/web.php` (expenses routes + owner nav)
- `lang/en/reports.php`, `lang/hi/reports.php` (P&L / net-profit labels)

---

## Traceability

- **Expenses module** (Decision 1–3, 6): new `expenses` table, `Expense` model,
  `ExpenseCategory`, owner-only CRUD screen — operating-expenses-only,
  soft-delete, manual entry.
- **Owner-only + tenant isolation** (Decision 4): `ResolvesOwnedTenant`,
  tenant-pinned, RLS + app scope, owner re-check on mutate.
- **Net Profit / P&L** (Decision 5, Computable): `DashboardReportService`
  extension, P&L block, per-category breakdown, net-profit trend + chart, loss
  and zero-sales handled.
- **Estimated caveat** (Decision 5): Net Profit labelled estimated, caption kept.
- **Testing:** unit (category, service math incl. loss + zero-sales) + feature
  (CRUD, isolation, dashboard P&L render).
