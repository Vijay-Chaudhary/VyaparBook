# Owner Reporting Dashboard — Phase 0 Design

**Date:** 2026-07-22
**Status:** Approved (design); ready for implementation plan
**Scope:** Phase 0 of a multi-phase "Management Dashboard" for business owners.

---

## Background

Owners currently keep their monthly management view in an external Excel/Google
Sheets workbook: daily-entry sheets (Daily Sales, Production, Raw Material
Purchase, Expense, Customer/Supplier) feed one "Management Dashboard" tab with
KPI tiles, a P&L, a 12-month trend table, trend charts, and summary tables.

None of this exists in VyaparBook today. The React app (`/app/*`) has a
deliberately thin `Home` screen ("the shopkeeper opens the app to record
something or to check who owes, not to read a dashboard") and no
reporting/analytics/P&L surface anywhere.

Rebuilding the whole workbook is **not one feature** — it spans several
subsystems, two of which do not exist in the app at all (Expenses, Suppliers).
It is therefore decomposed into phases, each with its own spec → plan → build
cycle. **This document specs Phase 0 only.**

### Phase decomposition (for context)

| Phase | Adds | Lights up |
|-------|------|-----------|
| **0 (this doc)** | Owner dashboard shell + read-only aggregation over existing data. No new tables. | Sales today/month, Customer Outstanding, Production, Low-Stock, Product Performance, Monthly Sales + Production charts |
| 1 | `Expense` module | Total Expenses, Operating-Expenses P&L, Net Profit, Margin %, Monthly Expenses chart |
| 2 | `Supplier` + Raw-Material-Purchase (with cost) | Supplier Outstanding, Raw-Material cost column, true Gross Profit, Cost per Kg, **Stock Value** |
| 3 | Cash Balance & full P&L tie-out | Opening Cash input, derived Cash Balance, remaining top-line tiles |

---

## Decisions (locked)

These were settled during brainstorming and drive the design:

1. **Surface:** Blade, server-rendered, **online-only**, **owner-only** — same
   genre as `/billing` and the admin console, not the offline `/app/*` counter
   screens. A monthly management report is an occasional owner tool, not a
   counter operation, so the offline-first mandate (PRD §9) does not apply.
2. **Period:** **Month + year picker.** Tiles/insights show the selected month;
   trend shows Jan–Dec of the selected year. Service takes an explicit
   `(year, month)`.
3. **Deferred blocks:** **Hidden entirely** in Phase 0. Nothing on screen is a
   placeholder or a fake zero. Deferred blocks appear only as later phases ship.

---

## What is computable in Phase 0 (and what is not)

Verified against the schema:

- `ProductPack.default_cost_price` **exists** → Product-wise Performance
  (Est. Cost / Est. Profit / Margin %) and Highest-Profit Product are
  computable now.
- `RawMaterial` has **no cost field** and `StockMovement` has **no rate/amount**
  → **Stock Value, Cost/Kg, and the Raw-Material cost column have no source
  data** and are deferred to Phase 2.

**In Phase 0 (shown):**

- Total Sales Today, Total Sales This Month
- Customer Outstanding — total + per-customer summary list
- Production This Month (Kg)
- Low-Stock Alert — list + count
- Highest Selling Product, Highest Profit Product
- Product-wise Performance (qty sold, sales, est cost, est profit, margin %) for the year
- Monthly **Sales** trend (table column + chart)
- Monthly **Production** trend (table column + chart)

**Out of scope in Phase 0 (hidden, deferred):**

- Expenses / Operating-Expenses P&L, Gross Profit, Net Profit, Profit Margin %
  (→ Phase 1)
- Supplier Outstanding, Raw-Material cost column, Stock Value, Cost/Kg
  (→ Phase 2)
- Cash Balance, Opening Cash (→ Phase 3)
- Net-Profit line chart (needs expenses)
- CSV/PDF export, caching layer

---

## Architecture

Follows the existing owner-tool pattern (`Web\BillingController`): the web
(session) guard carries a user but **no tenant**, so the controller resolves the
owned business from the caller's own membership and pins the tenant itself.

### Route & access

- `GET /reports/dashboard` — route name `reports.dashboard`, inside the existing
  `auth` group.
- **Owner-only**, resolved from `Membership` where `role = 'owner'` — never
  trusted from the request. `?business=<id>` scopes to a specific owned business
  (only if the caller owns it); with none, the sole owned business is used.
- Query params `year`, `month` — default to today; validated/clamped.
- **Read-only, so deliberately outside the write plan-gate** — a lapsed owner
  can still view reports, mirroring how `/billing` stays reachable.
- Non-owner, or a guessed/unowned `business` id → **redirect to `/app`**, never a
  403 (matches `BillingController`).

### Components (each single-purpose, independently testable)

1. **`App\Http\Controllers\Web\ReportController::show(Request): View|RedirectResponse`**
   - Resolves owned business id; null → redirect to `/app`.
   - Validates/clamps `year` (e.g. 2020..current) and `month` (1..12).
   - Runs the service inside `runInTenant($businessId, …)` — pins the RLS GUC +
     app-level tenant scope + owner role, in a read transaction.
   - Passes a `DashboardReport` DTO + period + businessId to the view.

2. **`App\Services\DashboardReportService::forMonth(int $year, int $month): DashboardReport`**
   - The single home for all aggregation. Runs under the already-pinned tenant
     context (RLS + app scope both active — defense in depth).
   - Composes **set-based** sub-queries (no per-row loops):
     - `salesToday`, `salesForMonth` — `Σ Sale.total`.
     - `customerOutstanding` — total + per-customer summary (see Correctness).
     - `productionForMonth` — `Σ ProductionBatch.output_kg`.
     - `lowStock` — raw materials where on-hand (`Σ` signed `StockMovement.qty`)
       `≤ reorder_level`; list + count.
     - `productPerformance` — per product-pack for the year: qty sold, sales,
       est cost (`qty × default_cost_price`), est profit, margin %. Highest
       selling / highest profit derived from this set.
     - `monthlyTrend` — 12 rows: `Σ` sales and `Σ` production kg per month of the
       year (only the Phase-0 columns).

3. **`App\Reports\DashboardReport`** — a `readonly` DTO of value objects, so the
   Blade view carries no logic.

4. **View:** `resources/views/reports/dashboard.blade.php` + block partials
   (tiles, key-insights, product table, trend table, charts), on the same Blade
   layout `/billing` uses. i18n via `__()`. Print-friendly (the workbook is a
   printable report).

5. **`App\View\Components\SvgBarChart`** — takes 12 numeric values → renders an
   inline SVG bar chart. Server-side, no external JS/chart library (works in
   print, needs no assets, trips no CSP). Used for Monthly Sales and Monthly
   Production.

### Targeted refactor (in-scope)

`ownedBusinessId()` + `runInTenant()` are currently duplicated across
`OnboardingController` and `BillingController`, and this controller would be a
third/fourth copy. Extract them into a small **`App\Http\Controllers\Concerns\ResolvesOwnedTenant`**
trait and reuse it here. Focused improvement only — no unrelated refactoring.
(The billing/onboarding controllers may adopt the trait opportunistically, but
that is not required by Phase 0 and must not change their behavior.)

---

## Data flow

```
GET /reports/dashboard?business=B&year=2026&month=7
  → ReportController::show
      auth user; ownedBusinessId(B) under user RLS context
        → null  ⇒ redirect('/app')
      validate/clamp year, month
      runInTenant(B):                         # transaction; RLS GUC + app scope + role=owner
        DashboardReportService->forMonth(2026, 7)
          → set-based aggregate queries (under RLS + scope)
          → DashboardReport DTO
  → view('reports.dashboard', [report, period, businessId])
      tiles / insights / product table / trend table / 2× SvgBarChart
```

---

## Correctness: customer outstanding must match the khata

The dashboard's customer-outstanding figures **must** reproduce
`App\Services\KhataService`'s identity exactly:

```
outstanding = opening_balance + Σ sale.total − Σ payment.amount
```

`KhataService` remains the source of truth for the formula. The dashboard,
however, computes it as **one aggregate SQL query across all customers**, not by
looping `KhataService::outstandingFor()` per customer (that would be N+1).

**Invariant (asserted in tests):** the dashboard's total outstanding equals
`Σ KhataService::outstandingFor($customer)` over all customers. If the two ever
disagree, one is wrong.

Money uses server-side bcmath/decimal discipline — **never floats** — matching
`KhataService`. Margin % guards divide-by-zero (zero sales → `0.0%`).

---

## Error handling / edge cases

- Non-owner, or unowned/guessed `business` id → redirect to `/app` (never 403).
- Invalid/out-of-range `year`/`month` → clamp to current period.
- Empty shop (no sales/customers/materials) → per-block empty states (the app's
  empty-state convention), never blank tiles or crashes.
- Zero sales → margin `0.0%`, no division error.
- All reads run under RLS **and** the app-level tenant scope (defense in depth).
- Performance: every figure is a set-based aggregate (no N+1); existing
  `business_id` indexes cover them; trend is 1–2 grouped queries.

---

## Testing (Pest)

**`DashboardReportService`:**
- Parity: dashboard total outstanding `==` `Σ KhataService::outstandingFor`.
- `salesToday` / `salesForMonth` bucket the right rows by date.
- Low-stock boundary: on-hand exactly `== reorder_level` vs just below.
- Product margin math incl. null `default_cost_price` handling.
- Monthly trend buckets sales/production into the correct month.
- Empty shop → all zeros, no error.

**HTTP (`ReportController`):**
- Owner → 200 with the expected blocks.
- Non-owner → redirect to `/app`.
- Guessed/unowned `business` id → redirect to `/app`.
- Tenant isolation: owner of A cannot read B's numbers.
- `year`/`month` validation/clamping.

**`SvgBarChart`:**
- 12 values → N bars, correct scaling.
- All-zero input renders without divide-by-zero.

Tests follow the suite's existing conventions (privileged connection for setup
rows; RLS/isolation assertions).

---

## Scope guardrails (YAGNI)

Explicitly **not** in Phase 0: expenses/P&L, suppliers, cash balance, stock
value, cost/kg, gross/net profit, net-profit chart, export/PDF, caching. Each is
owned by a later phase and stays hidden until then.

## Navigation

Add an **owner-only** link to `/reports/dashboard` from the `/app` `Home` screen,
alongside the existing owner billing link (`isOwner && !readOnly`), passing the
current `business` through so a multi-shop owner lands on the shop they are
viewing. Blade link out of the SPA (online-only), mirroring the billing link.
