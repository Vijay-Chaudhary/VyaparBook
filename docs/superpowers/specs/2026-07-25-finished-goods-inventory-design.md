# Finished-Goods Inventory — Design

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

**Date:** 2026-07-25
**Status:** Draft (design); awaiting sign-off.
**Scope:** PRD §18 Phase 3, "finished-goods packed inventory". Answers *how much
of each product do I actually have?* — production in, sales out, on-hand per
product. **Derived-only: no new tables, no migration, no change to the offline
sale or production screens.**

---

## Background

Stock today means **raw materials only**: `stock_movements.raw_material_id` is
non-nullable, so besan and oil have a ledger while the namkeen made from them
has none. Production records `output_kg` and sales record packs, and nothing
reconciles the two — a shop cannot ask whether it can fill tomorrow's order
without walking the godown.

Everything needed is already recorded:

| Direction | Source | Quantity |
|---|---|---|
| **In** — produced | `production_batches` | `output_kg` per `product_id` |
| **Out** — sold | `sale_lines` → `product_packs` → `pack_sizes` | `qty × weight_kg` |

A sale reversal writes lines with `qty = -qty` (`SaleController::void`), and a
return is a negative-qty line by design (`sale_lines.qty` comment), so
`Σ qty × weight_kg` **self-nets** — no row is ever excluded or mutated, the same
"always recomputable" discipline (PRD §9) as outstanding and cash flow.

## Decisions

1. **Tracked by weight, not pack count.** Production yields bulk kg; packing
   happens on the way out. Deriving kg from what is already recorded means the
   feature works **retroactively over all existing history**, needs no new input
   from the owner, and leaves the offline React production and sale screens
   untouched — no Dexie schema change, no sync change, no migration of user
   behaviour.

   The honest cost: it reports "12.400 kg of Aloo Bhujia", not "62 pouches". For
   a shop that packs to order that is the truer number anyway; for one that
   pre-packs, pack-level counting is a separate phase, and only worth building
   once we know shops actually pre-pack.

2. **Derived-only, no new tables.** Consistent with the reporting phases: on-hand
   is a query over `production_batches` and `sale_lines`, never a stored counter
   that can drift from the events that produced it.

3. **Negative on-hand is shown, not clamped.** Selling more than was produced
   means unrecorded production, a mis-keyed batch, or a wrong pack size — all
   things the owner needs to see. Clamping at zero would hide exactly the data
   error the ledger exists to reveal, so it renders in the danger colour like a
   loss elsewhere on the dashboard.

4. **No reorder threshold.** `products` has no reorder level (only
   `raw_materials` does), and inventing one would mean a migration and a form
   this phase does not need. Low-stock alerting for finished goods is deferred
   rather than half-built.

5. **Quantities are bcmath at scale 3.** `output_kg` is `decimal(12,3)` and
   `weight_kg` is `decimal(8,3)`; kg never becomes a float.

## The definition

```
produced(product) = Σ production_batches.output_kg
sold(product)     = Σ (sale_lines.qty × pack_sizes.weight_kg)   -- self-nets returns/voids
on_hand(product)  = produced − sold                             -- may be negative
```

All-time since inception, like raw-material on-hand — not per month. Archived
products are excluded, matching how the other dashboard tables read.

## Architecture

A read-only, tenant-pinned `FinishedGoodsService` (two grouped queries), a
`FinishedGoodsRow` value object, additive fields on `DashboardReport`, and one
new Blade partial on the existing `/reports/dashboard`. No controller, no route,
no migration — the same shape as the Phase 3 cash-flow section.

**Files:** `app/Services/FinishedGoodsService.php`,
`app/Reports/FinishedGoodsRow.php`,
`resources/views/reports/partials/finished-goods.blade.php`, additive fields on
`DashboardReport`/`DashboardReportService`, `lang/{en,hi}/reports.php`.

## Error handling / edge cases

| Case | Behaviour |
|---|---|
| Produced, never sold | on-hand = produced. |
| Sold with no production | Negative on-hand, shown in danger colour (Decision 3). |
| Sale reversal / return | Self-nets via negative `qty`; no exclusion logic. |
| Several pack sizes of one product | Each converts by its own `weight_kg` and sums. |
| Product never produced or sold | Omitted — an empty row is noise. |
| Archived product | Excluded. |
| Empty shop | Empty section with an explanatory line, not a broken table. |

## Testing

- **Unit** — `FinishedGoodsServiceTest`: production only; sale converts by pack
  weight; two pack sizes of one product; a return and a full reversal both
  self-net; sold-without-production goes negative; archived excluded; tenant
  isolation.
- **Unit** — `DashboardReportServiceTest`: the section is assembled and empty
  shops read zero.
- **Feature** — `ReportsDashboardTest`: the finished-goods table renders with
  its figures.

## Traceability

- PRD §18 Phase 3 "finished-goods packed inventory" → delivered by weight;
  pack-count deferred with the reasoning in Decision 1.
- PRD §9 always-recomputable → derived, no stored counter.
- Multi-tenant isolation → RLS **and** an explicit `business_id` scope, tested.
