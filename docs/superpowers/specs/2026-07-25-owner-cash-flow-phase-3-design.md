# Cash Flow — Phase 3 Design

**Date:** 2026-07-25
**Status:** Draft (design); awaiting sign-off.
**Scope:** Phase 3 of the multi-phase Management Dashboard. Adds a **cash-flow**
view to the owner dashboard: money actually in vs. money actually out, and the
running cash position — the last figure the P&L lineage was missing.

---

## Background

Phases 0–2b built an **accrual** picture of the business: a sale counts as
revenue the day it is billed (`sales.total`), a purchase as cost when it is
received, an expense when it is `spent_on`. Net Profit (Phase 1) and true Gross
Profit (Phase 2b) are all accrual figures.

None of that tells the owner whether there is money in the drawer. A profitable
month can still run the owner dry if customers pay late and suppliers are paid
on time — the single most common way a distribution business fails. The Phase 2a
and 2b specs both flag this explicitly:

> "Supplier payments **do not touch cash** (Phase 3) — they only reduce supplier
> outstanding, like expenses don't touch cash." — Phase 2a, §Decisions

Phase 3 makes them touch cash. Everything needed is already recorded and needs
no new table (the scope decision for this phase):

| Cash direction | Source table | Amount | Date | Notes |
|---|---|---|---|---|
| **In** — customer collections | `payments` | `amount` | `payment_date` | Reversals are rows with a negated `amount`, so `Σ amount` self-nets. No soft-delete. |
| **Out** — supplier payments | `supplier_payments` | `amount` | `payment_date` | Filter `archived_at is null`. |
| **Out** — operating expenses | `expenses` | `amount` | `spent_on` | Filter `archived_at is null`. |

The three are the only rows in the system that represent money changing hands.
Sales and purchases are deliberately excluded — billing a customer or receiving
stock on credit moves outstanding, not cash. That exclusion **is** the feature:
the gap between the accrual P&L and this view is exactly what an owner needs to
see.

## Decisions (locked in scoping)

1. **Derived-only, no new tables.** Cash flow is computed from `payments`,
   `supplier_payments`, and `expenses` — the same "always recomputable"
   discipline (PRD §9) that governs outstanding, on-hand, and Phase 2b's COGS.
   Accepted consequence: archiving an old supplier payment or expense re-writes a
   past month's cash figure, exactly as it already does for supplier outstanding
   and net profit.

2. **Cash-in = customer payments only.** Not sales. A credit sale is revenue in
   the P&L but ₹0 cash until collected — surfacing that divergence is the point.
   Reversals net automatically via their negated amount (no `archived_at` on
   `payments`; the append-only ledger of PRD §Ledger).

3. **Cash-out = supplier payments + operating expenses**, both non-archived. Not
   purchases (accrual), not COGS (a costing abstraction, never a cash event).

4. **Instrument-agnostic.** `payments.mode` / `supplier_payments.mode` (cash /
   UPI / bank) are **not** filtered. Every recorded collection or payout is a
   cash-flow event regardless of instrument — "cash flow" here means money
   movement, not physical notes. A future phase may split the position by
   instrument; Phase 3 reports one number.

5. **Running position is "net cash recorded by VyaparBook", not a bank
   balance.** There is no stored opening-cash figure (that would be a new table,
   out of scope), so the position is the cumulative sum of every cash event to
   date: `Σ all cash-in − Σ all cash-out` from the first record. It answers "how
   much cash has this business generated since it started using the app", and the
   view labels it as such — the same honesty marker Phase 2b uses for estimated
   COGS. The running line stays continuous across the year picker by seeding each
   year from the cumulative net of everything **before** that year (three
   `< Jan 1` sum queries).

6. **Its own dashboard section, not another P&L trend column.** The 12-month
   trend table is already six columns of accrual figures; folding cash in would
   invite "why doesn't net profit equal net cash?". Cash flow gets a dedicated
   tile, a small monthly table, and its own net-cash chart, so the accrual/cash
   distinction is visible rather than blurred.

## The cash-flow definition

```
cash_in(month)       = Σ payments.amount            where payment_date in month
supplier_out(month)  = Σ supplier_payments.amount   where payment_date in month, not archived
expense_out(month)   = Σ expenses.amount            where spent_on     in month, not archived
cash_out(month)      = supplier_out + expense_out
net_cash(month)      = cash_in − cash_out                     (may be negative)

opening_position(year) = Σ (all cash events strictly before Jan 1 of year)
position(month)        = opening_position(year) + Σ net_cash(Jan..month)
```

Every sum is a Postgres aggregate grouped by month; every subtraction/cumulation
stays in bcmath at scale 2, matching `expensesTrend` and `grossProfitTrend`.

## Implementation

A new **`CashFlowService`**, tenant-pinned like every other report service
(explicit `->where('business_id', …)` over FORCE'd RLS — defense in depth, never
one layer alone), exposing:

- `cashInTrend(businessId, year): list<string>` — 12 scale-2 strings, `Σ
  payments.amount` grouped by month.
- `supplierOutTrend(businessId, year): list<string>` — 12 strings, non-archived
  `supplier_payments`.
- `expenseOutTrend(businessId, year): list<string>` — reuses the exact query
  shape of `DashboardReportService::expensesTrend` (keep it there and call it, or
  mirror it — decided in the plan; no logic divergence either way).
- `openingPosition(businessId, year): string` — cumulative net cash of all events
  with a date `< Jan 1` of the year. Three sums, one bcmath fold.

Then in `DashboardReportService::forMonth`, assemble a `list<CashFlowRow>` (one
per month) and the selected-month figures, and hang them off `DashboardReport`:

**New `CashFlowRow` DTO** (`app/Reports/CashFlowRow.php`), 12 per report:
```php
final readonly class CashFlowRow {
    public function __construct(
        public int    $month,             // 1..12
        public string $cashInRupees,
        public string $cashOutRupees,     // supplier + expense
        public string $netCashRupees,     // in − out (may be negative)
        public string $positionRupees,    // running, seeded from openingPosition
    ) {}
}
```

**New fields on `DashboardReport`** (additive — nothing renamed, unlike 2b):
- `cashInMonthRupees`, `supplierPaidMonthRupees`, `expensePaidMonthRupees`
- `netCashMonthRupees` (may be negative)
- `cashPositionRupees` — running position through the **selected** month
- `cashTrend: list<CashFlowRow>` — the 12 rows, for the section table + chart

The running `positionRupees` is computed once while folding the trend
(`opening + running Σ net`), so the row's position and the headline
`cashPositionRupees` (position at the selected month) come from the same walk and
cannot disagree.

**Render** (Blade, online-only, owner-only — the existing `/reports/dashboard`):
- A **Cash Position** tile in `partials/tiles.blade.php` (net-cash-for-month
  sub-label, loss-aware danger colour when the month is cash-negative, matching
  the Net Profit tile).
- A new `partials/cash.blade.php`: a compact monthly table (In / Out / Net /
  Position) and a reused `SvgBarChart` of `netCashRupees` — green above zero, the
  danger colour below, like the net-profit chart.
- New `reports` translation keys in `lang/en` and `lang/hi` (cash flow, cash in,
  cash out, net cash, cash position, and the "money recorded, not a bank balance"
  helper caption).

## Error handling & edge cases

- **No payments/expenses yet** → every trend entry `0.00`, position `0.00`; the
  section renders zeros, never null.
- **Cash-negative month** (paid suppliers/expenses faster than collected) →
  `netCashRupees` negative, rendered in the danger colour; the running position
  can legitimately go negative and is shown as-is (it is net-since-inception, not
  a real overdraft).
- **Reversed customer payment** → the negated row nets it out of `cash_in`
  automatically; no special-casing, and a reversal dated in a later month
  correctly reduces that later month's cash-in.
- **Archived supplier payment / expense** → excluded via `archived_at is null`,
  consistent with supplier outstanding and the expense P&L.
- **Year with no prior history** → `openingPosition` = `0.00`; the running line
  starts from zero, correct for a tenant's first year.
- **Subscription payments** (`subscription_payments`) are the tenant's SaaS
  billing to the platform, **not** the business's own cash — explicitly excluded.
- Tenant isolation: every query carries `business_id` and runs under the caller's
  tenant pin, RLS FORCE'd beneath — the Phase 0/2b pattern, planned in from the
  start.

## Testing (TDD, Pest)

- **Unit (`CashFlowService`):** cash-in nets a reversal; supplier + expense
  cash-out excludes archived rows; a month with more out than in yields negative
  net cash; `openingPosition` sums only pre-year events; tenant isolation (a
  second tenant's payments never leak in).
- **Unit (`DashboardReportService`):** a mixed month asserts `cashPositionRupees`
  = opening + Σ net through the month, and that the selected-month `CashFlowRow`'s
  `positionRupees` equals the headline — one walk, no drift.
- **Feature (dashboard):** the Cash section and tile render; a cash-negative
  month shows the danger colour; no cross-tenant leakage.
- **Regression:** all Phase 0–2b dashboard tests keep passing — `DashboardReport`
  changes are purely additive.

## Traceability

- Decisions 1–3 → `CashFlowService`'s three trends and the derived, no-new-table
  build; the sales/purchase exclusion.
- Decision 4 → `mode` unfiltered; noted for a future instrument split.
- Decision 5 → `openingPosition`, the running walk, and the "recorded cash, not a
  bank balance" caption.
- Decision 6 → dedicated `partials/cash.blade.php` + tile, separate from the P&L
  trend.
- Phase 2a/2b "supplier payments / expenses do not touch cash (Phase 3)" → this
  is the phase that makes them touch cash.
- PRD §9 always-recomputable → derived figures, no snapshot columns.
