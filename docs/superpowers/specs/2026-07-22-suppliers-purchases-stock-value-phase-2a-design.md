# Suppliers, Costed Purchases & Stock Value — Phase 2a Design

**Date:** 2026-07-22
**Status:** Approved (design); building.
**Scope:** Phase 2a of the multi-phase Management Dashboard. Builds on Phase 0 (dashboard) and Phase 1 (expenses/net profit).

---

## Background

Phase 2 (Suppliers + purchases-with-cost + true COGS) spans several subsystems.
It is decomposed; **this doc is Phase 2a** — Suppliers, costed Raw-Material
Purchases, and weighted-average stock valuation. It lights up **Supplier
Outstanding**, **Cost/Kg**, and **Stock Value**. Deferred: true-COGS Gross Profit
(Phase 2b) and Cash (Phase 3).

Today `RawMaterial` has no cost field and `stock_movements` record `kind`/`qty`
but no money; there are no Supplier or Purchase models. Gross Profit is
*estimated* from `ProductPack.default_cost_price`.

## Decisions (locked in brainstorming)

1. **Blade owner-tool + `Purchase` entity** (not extending the offline stock
   flow). New Supplier/Purchase/supplier-payment tables in Blade, online-only,
   owner-only areas like `/expenses`. Recording a purchase also writes a costed
   stock-in `stock_movement`, so on-hand stays correct and the offline/sync
   surface is unchanged.
2. **Weighted-average** valuation, purchase-lifetime: `Cost/Kg = Σ purchase.total
   ÷ Σ purchase.qty` per material; `Stock Value = Σ (on-hand × Cost/Kg)`.
3. Gross Profit **stays estimated** in 2a (true COGS is 2b). Supplier payments
   **do not touch cash** (Phase 3) — they only reduce supplier outstanding, like
   expenses don't touch cash.

## Data model (new tenant tables — RLS + `BelongsToTenant`, uuid PK, soft-delete, NO sync columns)

- **`suppliers`**: `business_id, uuid, name, village?, phone?, opening_balance (numeric 12,2), archived_at`.
- **`purchases`**: `business_id, uuid, supplier_id, raw_material_id, purchase_date, qty (numeric 12,3), unit_cost (numeric 12,2), total (numeric 12,2), note?, created_by, archived_at`. `total` computed server-side (`qty × unit_cost`, bcmath), never from request.
- **`supplier_payments`**: `business_id, uuid, supplier_id, payment_date, amount (numeric 12,2), mode, note?, created_by, archived_at`.
- **`stock_movements`** gains nullable **`purchase_id`** (mirrors `production_batch_id`), FK → purchases, `index(business_id, purchase_id)`.

Recording a purchase creates a `stock_movement` (`kind='in'`, `qty`, `purchase_id` set, `created_by` stamped) — server-side, mirroring `ProductionWriter`. Deleting a purchase archives it **and removes its linked stock-in movement** so on-hand does not overcount.

## Aggregation

- **Supplier Outstanding** (mirrors `KhataService`/customer outstanding): per supplier `opening_balance + Σ purchases.total − Σ supplier_payments.amount`; total = Σ, sorted highest-first. bcmath scale 2.
- **Cost/Kg** per material: `Σ purchases.total ÷ Σ purchases.qty` (scale-2), null/`'0.00'` when never purchased.
- **Stock Value**: `Σ over materials (StockService::onHandFor × Cost/Kg)`, scale 2. Materials never purchased contribute 0 (no cost source).

Lives in a new `PurchaseService` (or extends the dashboard/stock services) — set-based, tenant-pinned, explicit `business_id`, no floats.

## Surfaces (Blade, owner-only, tenant-pinned via `ResolvesOwnedTenant`; not plan-gated, like billing/expenses)

- **`/purchases`**: month picker; record a purchase (supplier dropdown, material dropdown, qty, unit cost → total shown); list + month total; delete (archive + reverse stock-in).
- **`/suppliers`**: list with outstanding (highest first); add a supplier; per-supplier detail = ledger (purchases + payments, running) + record-payment form.
- **Dashboard**: new **Stock Value** tile; a **Supplier Outstanding** section (total + top suppliers, like customer outstanding); a **stock valuation** table (material · on-hand · Cost/Kg · value) — folded into the existing low-stock/materials area.

## Error handling & edge cases

- Purchase with `qty ≤ 0` or `unit_cost ≤ 0` → validation error.
- Delete-reversal: removing the linked `in` movement inside the same tenant-pinned transaction; if the material's on-hand would go negative afterward that's allowed (informational), consistent with existing manual movements.
- Material never purchased → Cost/Kg and its stock value are 0 (shown as `—`/₹0).
- Supplier with no activity → outstanding = opening_balance.
- Idempotent create by `uuid` (double-submit safe), like expenses/billing.
- Owner re-check on every mutate; another tenant's supplier/purchase id cannot be touched.

## Testing (TDD, Pest)

- Unit: supplier outstanding (+ isolation), weighted-avg Cost/Kg, Stock Value, purchase→movement creation, delete-reversal (on-hand restored), all bcmath.
- Feature: `/purchases` and `/suppliers` CRUD, access/owner-isolation, idempotency, delete reverses stock.
- Feature: dashboard render — Stock Value tile, Supplier Outstanding section, stock table.
- **DPDP:** register `suppliers`, `purchases`, `supplier_payments` in `TenantExporter::TABLES` and `TenantEraser::DELETE_ORDER` in correct FK order (purchases & supplier_payments before suppliers; stock_movements already before purchases must be handled — see plan). Extend `TenantEraseTest` seed.

## Traceability
- Decisions 1–3 → data model + surfaces + aggregation above.
- Supplier Outstanding, Cost/Kg, Stock Value → the three computed figures + dashboard.
- Isolation/DPDP → RLS + owner re-check + registry updates (the Phase-1 lesson, planned in from the start).
