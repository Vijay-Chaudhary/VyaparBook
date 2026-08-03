# True-COGS Gross Profit — Phase 2b Design

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

**Date:** 2026-07-23
**Status:** Approved (design); building.
**Scope:** Phase 2b of the multi-phase Management Dashboard. Builds on Phase 2a
(suppliers, costed purchases, weighted-average material cost).

---

## Background

Phase 2a gave every raw material a real weighted-average ₹/kg from costed
purchases. Gross profit, however, is still **estimated**: `grossProfitTrend`
values a sale line at `qty × product_packs.default_cost_price`, a number the
owner typed in (or that `CatalogService` seeded from
`Product.base_cost_per_kg × PackSize.weight_kg`). It has never been checked
against what production actually consumed.

Everything needed to close that gap is already recorded. `production_batches`
holds `product_id` and `output_kg`; `material_consumptions` holds the qty of
each raw material a batch consumed; Phase 2a prices those materials. So the
actual cost of a produced kg is derivable, and PRD §Phase 3 already names
"actual cost-per-kg from production" as the intended destination.

## Decisions (locked in brainstorming)

1. **Lifetime weighted average**, per product: `cost/kg = Σ (batch material
   cost) ÷ Σ output_kg` over all that product's batches. Consistent with how 2a
   values materials, stable against one odd batch, one number per product.
2. **Always recompute** — no snapshot column on `sale_lines`. Matches the PRD §9
   "always recomputable" principle already governing outstanding and on-hand.
   **Accepted consequence:** buying materials later moves the weighted average,
   so a past month's gross profit can change after the fact. This is the same
   property Stock Value already has in 2a.
3. **Fall back to the existing estimate** for a product with no batches
   (bought-in goods, or anything sold before its first batch): value the line at
   `default_cost_price` exactly as today. Nothing regresses, every line gets a
   cost, and the report marks how much of the figure is still estimated.
4. **Replace, don't duplicate**: one Gross Profit figure on the dashboard, true
   wherever production data exists, carrying a marker for the estimated share.
   Two competing profit numbers on one screen would invite "which is real?".

## The costing chain

```
purchases            → material ₹/kg      (Phase 2a: Σ total ÷ Σ qty)
material_consumptions→ batch material cost (Σ qty × material ₹/kg)
production_batches   → product ₹/kg       (Σ batch cost ÷ Σ output_kg)
pack_sizes.weight_kg → pack cost          (product ₹/kg × weight_kg)
sale_lines           → line COGS          (qty × pack cost)
```

Gross profit for a period = `Σ line_total − Σ line COGS`.

**Cost means materials only.** Labour and overhead are not recorded per batch —
salaries and electricity are operating expenses, and they already sit below the
gross line in the P&L. Folding them in here would double-count them.

## Implementation

A new **`CogsService`**, tenant-pinned like every other report service, exposing:

- `materialCostPerKg(businessId): array<materialId, string>` — reuses the 2a
  weighted average.
- `productCostPerKg(businessId): array<productId, string>` — the chain above;
  absent from the map when the product has no batches or no output.
- `packCosts(businessId): array<packId, PackCost>` — per pack: the ₹ cost of one
  pack and whether it came from production or from the estimate.

**Three queries, all sums; every division in bcmath.** Postgres groups
`purchases` by material, `material_consumptions` by (product, material), and
`production_batches` by product. The divisions and multiplies stay in PHP at the
scales 2a established, so the dashboard and a single-product answer cannot
disagree — the same discipline (and the same reason) as
`PurchaseService::weightedAvg`.

Then in `DashboardReportService`:

- `grossProfitTrend` and the monthly gross figure value each line with
  `packCosts`, falling back to `default_cost_price`.
- `productPerformance` uses the **same** costing, so "highest profit product"
  cannot contradict the headline.
- A new figure: the month's revenue whose cost is still an estimate, so the P&L
  can say how much of the gross profit is not yet actual.

`DashboardReport.estGrossProfitMonthRupees` is renamed
**`grossProfitMonthRupees`** — it is no longer merely an estimate, and leaving
the old name would mislead the next reader.

## Error handling & edge cases

- Product with batches but `Σ output_kg = 0` → not costable; falls back to the
  estimate rather than dividing by zero.
- Product produced from materials that were never purchased → those materials
  contribute ₹0, so the product's cost/kg is understated. Treated as the
  estimate case for the marker: it is not a real actual cost.
- Pack with `default_cost_price` null AND no production → cost 0, as today.
- Over-consumption (negative on-hand) does not distort cost: consumption qty is
  what was used, independent of what was in stock.
- Archived purchases stay excluded (2a); archived batches/packs follow the
  existing report behaviour.
- A sale of a pack whose product's only batches are in a later month still
  costs at the lifetime average — accepted under decision 1.

## Testing (TDD, Pest)

- Unit: product ₹/kg from a two-batch, multi-material chain; pack cost via
  `weight_kg`; fallback to `default_cost_price` when a product has no batches;
  zero-output batch does not divide by zero; tenant isolation.
- Unit: gross profit for a month mixing a produced product and a bought-in one,
  asserting the true and estimated halves both land, and that
  `productPerformance` agrees with the headline gross figure.
- Feature: dashboard renders the gross profit figure and the estimated-share
  marker; no cross-tenant leakage.
- Regression: the existing dashboard tests keep passing on the renamed field.

## Traceability
- Decisions 1–4 → the costing chain, `CogsService`, the fallback, the single
  dashboard figure + marker.
- "Actual cost-per-kg from production" (PRD Phase 3) → `productCostPerKg`.
- Isolation → tenant-pinned service, explicit `business_id`, RLS underneath —
  the 2a pattern, planned in from the start.
