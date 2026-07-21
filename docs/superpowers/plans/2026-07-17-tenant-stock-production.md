# Tenant Stock & Production Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Build VyaparBook's raw-material **Stock** and **Production** modules on the same tenant-isolated, append-only, sync-ready foundation as the khata slice. Stock is a signed-movement ledger (`stock on hand = Σ movements`); Production records batches that consume materials, and **completing a batch draws stock down by writing `out` movements**, so on-hand is always real. Both modules are owner/admin-only (PRD §7).

**Architecture:** Four new tenant-owned tables in the existing Laravel 11 backend, each with a flat RLS policy (`business_id = current_setting('app.current_tenant')`) plus the `BelongsToTenant` global scope, `HasVersion`, and `HasSyncSequence` — following the catalog and khata slices exactly. All routes sit behind `auth:api` + `tenant.context` + `require.tenant`. Two design invariants carry over from khata:

- **Append-only ledger.** A `StockMovement` is immutable. Stock on hand is `Σ qty` over a material's movements — always recomputable, exact via bcmath. A correction is a new `adjust` movement, never an edit.
- **Idempotent, sync-ready.** Every top-level row (`raw_materials`, `stock_movements`, `production_batches`) carries a client `uuid` (`(business_id, uuid)` unique) and a `sync_seq`. `material_consumptions` is a child of a batch (like `sale_lines`): `sync_seq` but no `uuid`.

**The stock ↔ production link:** a `StockMovement` carries a nullable `production_batch_id`. A movement recorded by hand leaves it null; an `out` movement written when a batch is completed references the batch, so "why did stock drop" is answerable. Migration-wise, `stock_movements` is created first (pure Stock) and the `production_batch_id` column + FK are added by a later migration once `production_batches` exists — Stock stays the foundation, Production layers on top.

**Signed quantity + kind:** `stock_movements.qty` is the signed effect on stock. The API takes a `kind` and a positive magnitude and derives the sign — `in` → `+qty`, `out` → `−qty` — while `adjust` takes a signed delta directly. This makes `Σ qty` the on-hand total and stops an `out` from ever raising stock. Stock may go **negative** (over-consumption is recorded, not blocked — "soft-block, never data loss", PRD §8); the read flags it.

**Testing:** Pest, following the established conventions — no `RefreshDatabase`, `RefreshesTenantDatabase` via `tests/Pest.php`, `Model::on('pgsql_migrate')` for setup rows, exact decimal **string** money/quantity assertions.

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL (RLS, the existing `sync_seq_global` sequence), Pest, bcmath.

**Design source:** no standalone spec — folded in here. Domain intent: PRD §7 (RBAC), §10 (`RawMaterial`/`StockMovement`/`ProductionBatch`/`MaterialConsumption`). The Django sketch in §10 is superseded by the Eloquent/migration model below.

**Depends on:** the tenancy/auth core, catalog (`products` — a batch's finished good references it), and khata (`HasSyncSequence`, the `sync_seq_global` sequence, the `LedgerWriter`/idempotency and append-only patterns).

---

## Scope

**In scope:**
- `RawMaterial` (name, unit, reorder level), CRUD + archive/restore
- `StockMovement` — append-only signed ledger; record endpoint (in/out/adjust), idempotent by `uuid`
- `StockService` — on-hand (`Σ qty`, exact), below-reorder flag, movement ledger
- `ProductionBatch` + `MaterialConsumption` — batch create consumes materials and writes `out` stock movements in one transaction, idempotent by `uuid`
- `StockPolicy` — owner/admin only, for **all** stock + production reads and writes (PRD §7)
- `GET /stock`, `GET /stock/{material}`, `GET /production`, `GET /production/{batch}`
- Extend `GET /sync/pull` to stream the four new tables
- RLS + `BelongsToTenant` on all four tables; DB-level RLS proof; cross-tenant leak coverage

**Out of scope** (deferred, noted where relevant):
- **Offline `sync/push` for stock/production types** — these are owner/admin operations done at the facility, typically online. Pull carries the deltas (read side whole); push support for `stock_movement`/`production_batch` mutations is a later addition (the `uuid` columns are added now so it drops in cleanly, exactly as the catalog added sync columns before the endpoint).
- **Voiding/correcting a production batch** — a batch is create-only in v1. A stock miscount is fixed with an `adjust` movement; a wrong batch correction (reversing its consumption + output) is deferred.
- **Actual cost-per-kg from production** (PRD Phase 3) — `products.base_cost_per_kg` stays the catalog reference; production does not yet write back a computed cost.
- **Finished-goods packed inventory** (PRD Phase 3) — production records `output_kg` of a product; it does not create packed stock.
- Any frontend.

---

## RBAC (PRD §7)

"Stock & production" is a single owner/admin capability — salesman and accountant have **no** stock/production access, reads included. So unlike catalog/khata (open reads), **every** stock and production endpoint is gated by `StockPolicy::manage()` (owner/admin). This is the deliberate difference from the two prior slices.

---

## File Structure

```
backend/
  app/
    Models/
      RawMaterial.php                (new)
      StockMovement.php              (new)
      ProductionBatch.php            (new)
      MaterialConsumption.php        (new)
    Http/Controllers/Api/V1/
      RawMaterialController.php       (new — store, update, destroy, restore)
      StockMovementController.php     (new — store [idempotent])
      StockController.php             (new — index, show)
      ProductionController.php        (new — store [idempotent], index, show)
      SyncController.php              (modified — pull streams the new tables)
    Services/
      StockService.php               (new — onHandFor, ledgerFor, belowReorder)
      ProductionWriter.php           (new — createBatch: batch + consumptions + out movements)
    Policies/
      StockPolicy.php                (new — manage())
  database/
    migrations/
      2026_07_17_100001_create_raw_materials_table.php
      2026_07_17_100002_create_stock_movements_table.php
      2026_07_17_100003_create_production_batches_table.php
      2026_07_17_100004_create_material_consumptions_table.php
      2026_07_17_100005_add_production_batch_id_to_stock_movements.php
    factories/
      RawMaterialFactory.php
      StockMovementFactory.php
      ProductionBatchFactory.php
      MaterialConsumptionFactory.php
  routes/api.php                     (modified)
  README.md                          (modified — Stock & Production API)
  tests/
    Unit/
      StockServiceTest.php
      RawMaterialModelTest.php
    Feature/
      Stock/RawMaterialCrudTest.php
      Stock/StockMovementTest.php
      Stock/StockReadTest.php
      Production/ProductionTest.php
      Stock/StockProductionRbacTest.php
      Sync/SyncPullStockTest.php
      Tenancy/StockRlsTest.php
      Tenancy/CrossTenantLeakTest.php  (modified — stock/production cases)
```

**Conventions inherited (not re-derived):** `created_by` is a `foreignId` (bigint) to `users`; `business_id`/`uuid` are `$fillable`, trait-managed columns (`version`, `sync_seq`) and `created_by`/`archived_at` are not; factories set non-fillable columns via `afterMaking`; tenant-table test setup uses `Model::on('pgsql_migrate')`; RLS policy is the flat `NULLIF(current_setting('app.current_tenant', true), '')::uuid` shape with `ENABLE` + `FORCE` + `CREATE POLICY`.

---

## Task 1: RawMaterial model, migration, factory

**Files:** migration `…100001…`, `app/Models/RawMaterial.php`, `database/factories/RawMaterialFactory.php`, test `tests/Unit/RawMaterialModelTest.php`

- [x] **Migration** — `id` uuid PK; `business_id` FK; `uuid`; `name` (120); `unit` (20 — 'kg'|'litre'|'piece'|…, free text validated in the controller); `reorder_level` decimal(12,3) nullable; `archived_at` nullable; `version`; `sync_seq`; timestamps. `unique(['business_id','uuid'])`; `index(['business_id','sync_seq'])`. Then RLS `raw_materials_isolation`.
- [x] **Model** — `use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;` `$fillable = ['business_id','uuid','name','unit','reorder_level'];` casts `reorder_level` → `decimal:3`, `archived_at` → datetime, `version`/`sync_seq` → integer. `movements(): HasMany`.
- [x] **Factory** — business_id, uuid, name (faker word), unit `'kg'`, reorder_level `'10.000'`.
- [x] **Test** — uuid PK + positive `sync_seq`; `reorder_level` round-trips as a 3-decimal string; duplicate `(business_id, uuid)` throws. Migrate + run → PASS.
- [x] **Commit** — `feat: add RawMaterial model with RLS isolation policy`.

`reorder_level` is decimal(12,3): materials are weighed (0.5 kg), so 3 decimals mirror `pack_sizes.weight_kg`. Quantities across stock use the same scale.

---

## Task 2: StockMovement model, migration, factory

**Files:** migration `…100002…`, `app/Models/StockMovement.php`, factory, test folded into `StockMovementTest` (Task 6).

- [x] **Migration** — `id`; `business_id` FK; `uuid`; `raw_material_id` FK → `raw_materials`; `movement_date` date; `kind` (10 — `in`|`out`|`adjust`); `qty` decimal(12,3) **signed**; `note` (255) nullable; `created_by` `foreignId`→users; `version`; `sync_seq`; timestamps. `unique(['business_id','uuid'])`; `index(['business_id','raw_material_id'])`; `index(['business_id','sync_seq'])`. RLS `stock_movements_isolation`. **No `production_batch_id` yet** — added in Task 8's migration so Stock stands alone first.
- [x] **Model** — traits as above; `$fillable = ['business_id','uuid','raw_material_id','movement_date','kind','qty','note','production_batch_id'];` (`production_batch_id` is fillable so the production writer sets it; it stays null for manual movements). casts `qty` → `decimal:3`. `rawMaterial(): BelongsTo`.
- [x] **Factory** — unrelated defaults; `kind` `'in'`, `qty` `'100.000'`; `created_by` via `afterMaking`.
- [x] **Commit** — `feat: add StockMovement append-only signed ledger`.

`qty` is the signed effect: `+` raises stock, `−` lowers it. `kind` labels intent; the controller derives the sign from `kind` + magnitude so the two never disagree (Task 6).

---

## Task 3: StockService

**Files:** `app/Services/StockService.php`, test `tests/Unit/StockServiceTest.php`

- [x] **`onHandFor(RawMaterial): string`** — `Σ qty` via `selectRaw('coalesce(sum(qty),0)::text')` (exact, no float), scale 3.
- [x] **`belowReorder(RawMaterial): bool`** — `reorder_level !== null && onHand < reorder_level` (bccomp).
- [x] **`ledgerFor(RawMaterial): Collection`** — movements ordered by `movement_date` then `created_at`, each with a running on-hand whose last value equals `onHandFor`.
- [x] **Test** — on-hand sums signed movements exactly (in − out); negative on-hand is allowed and reported; below-reorder flag flips at the threshold; ledger running total ends at on-hand. → PASS.
- [x] **Commit** — `feat: add StockService with exact-decimal on-hand and reorder flag`.

---

## Task 4: StockPolicy

**Files:** `app/Policies/StockPolicy.php` (test via Task 12 RBAC)

- [x] **`manage(): bool`** → `in_array(app('tenant.role'), ['owner','admin'], true)`. Used by **every** stock and production endpoint, reads included (PRD §7). Mirrors `CatalogPolicy`.
- [x] **Commit** — `feat: add StockPolicy gating stock and production to owner/admin`.

---

## Task 5: RawMaterial CRUD + archive/restore

**Files:** `RawMaterialController.php`, routes, test `RawMaterialCrudTest.php`

- [x] Mirrors `CustomerController` (idempotent create by `uuid`) but gated on `StockPolicy::manage()`. Validate `uuid?`, `name` (required), `unit` (required, in a small whitelist), `reorder_level?` (numeric ≥ 0). `findOrFail` → cross-tenant 404; `DELETE` archives.
- [x] Routes `raw-materials` (store/update/destroy/restore) under `require.tenant`.
- [x] Test — create stamps tenant; **salesman and accountant both 403** (the key RBAC difference); idempotent uuid replay; cross-tenant 404. → PASS.
- [x] **Commit** — `feat: add raw material CRUD with idempotent create`.

---

## Task 6: StockMovement record

**Files:** `StockMovementController.php`, routes, test `StockMovementTest.php`

- [x] **`store`** — `StockPolicy::manage()`. Validate `uuid` (required), `raw_material_id` (required uuid), `movement_date` (required date), `kind` (required in `in|out|adjust`), `qty` (required numeric; `gt:0` for in/out, any non-zero for adjust), `note?`. Derive signed stored qty: `in` → `+qty`, `out` → `−qty`, `adjust` → `qty` as given (reject 0). Idempotent replay on `uuid`. `findOrFail` the material (cross-tenant 404). Stamp `created_by`.
- [x] Route `POST stock-movements`.
- [x] Test — an `in` raises on-hand, an `out` lowers it, `adjust` applies a signed delta; on-hand can go negative; idempotent replay; `out` with `qty ≤ 0` is 422; cross-tenant material 404; salesman 403. → PASS.
- [x] **Commit** — `feat: add stock movement recording with signed kinds`.

---

## Task 7: Stock reads

**Files:** `StockController.php`, routes, test `StockReadTest.php`

- [x] **`index`** `GET /stock` — every non-archived material with `on_hand`, `reorder_level`, `below_reorder` (`?include_archived=1` for the full view). Gated `manage()`.
- [x] **`show`** `GET /stock/{id}` — material + movement ledger (running on-hand) + `on_hand`. `findOrFail` → 404.
- [x] Test — on-hand matches Σ movements; below-reorder flag correct; ledger ordered with running total; cross-tenant 404; salesman/accountant 403. → PASS.
- [x] **Commit** — `feat: add stock summary and per-material ledger reads`.

---

## Task 8: ProductionBatch + MaterialConsumption models, migrations

**Files:** migrations `…100003…`, `…100004…`, `…100005_add_production_batch_id_to_stock_movements…`; models `ProductionBatch.php`, `MaterialConsumption.php`; factories; test folded into `ProductionTest` (Task 9).

- [x] **`production_batches` migration** — `id`; `business_id` FK; `uuid`; `product_id` FK → `products` (the finished good); `batch_date` date; `output_kg` decimal(12,3); `created_by` foreignId; `version`; `sync_seq`; timestamps. `unique(['business_id','uuid'])`; `index(['business_id','sync_seq'])`. RLS.
- [x] **`material_consumptions` migration** — `id`; `business_id` FK; `production_batch_id` FK → `production_batches` cascade; `raw_material_id` FK → `raw_materials`; `qty` decimal(12,3) (positive amount consumed); `version`; `sync_seq`; timestamps. `index(['business_id','production_batch_id'])`; `index(['business_id','sync_seq'])`. No `uuid` (child of the batch). RLS.
- [x] **`add_production_batch_id_to_stock_movements` migration** — add nullable `production_batch_id` + FK → `production_batches`, and `index(['business_id','production_batch_id'])`. (Runs after `production_batches` exists.)
- [x] **Models** — `ProductionBatch`: traits; `$fillable = ['business_id','uuid','product_id','batch_date','output_kg'];` casts `output_kg`→decimal:3; `product(): BelongsTo`, `consumptions(): HasMany`. `MaterialConsumption`: traits (BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion); `$fillable = ['business_id','production_batch_id','raw_material_id','qty'];` `batch()`, `rawMaterial()`.
- [x] **Factories** — `afterMaking` for `created_by`/`output_kg` on batch.
- [x] **Commit** — `feat: add ProductionBatch and MaterialConsumption models`.

---

## Task 9: Production batch create (draws stock down)

**Files:** `app/Services/ProductionWriter.php`, `ProductionController.php`, routes, test `ProductionTest.php`

- [x] **`ProductionWriter::createBatch(array $data): array`** (returns `[batch, created]`) — idempotent by `uuid`. In one transaction: create the `ProductionBatch`; for each consumption line create a `MaterialConsumption` (`findOrFail` the material → cross-tenant 404) **and** a `StockMovement` with `kind='out'`, `qty = −consumed`, `production_batch_id = batch.id`, `created_by` stamped. So finishing a batch draws stock down through the same ledger `GET /stock` reads. Extracted into a service (like `LedgerWriter`) so it is reusable and testable.
- [x] **`ProductionController::store`** — `StockPolicy::manage()`, validate (`uuid`, `product_id`, `batch_date`, `output_kg` > 0, `consumptions[]` each `{raw_material_id, qty>0}`), delegate to the writer, 201/200.
- [x] Route `POST production`.
- [x] Test — creating a batch records consumptions **and** lowers each material's on-hand by the consumed qty (assert via `StockService`); the `out` movements carry `production_batch_id`; idempotent replay creates nothing; over-consuming drives on-hand negative (allowed); cross-tenant material/product 404; salesman 403. → PASS.
- [x] **Commit** — `feat: add production batch create that draws stock down`.

---

## Task 10: Production reads

**Files:** `ProductionController.php` (index, show), routes, test folded into `ProductionTest.php`

- [x] **`index`** `GET /production` — batches (newest first) with product, date, output_kg. **`show`** `GET /production/{id}` — batch + its consumptions (material, qty). Gated `manage()`; `findOrFail` → 404.
- [x] Routes `GET production`, `GET production/{id}`.
- [x] Test — list returns the tenant's batches; show returns consumptions; cross-tenant 404; salesman 403. → PASS.
- [x] **Commit** — `feat: add production batch reads`.

---

## Task 11: Sync pull streams stock & production

**Files:** `SyncController.php` (pull), test `SyncPullStockTest.php`

- [x] Extend `pull` to include `raw_materials`, `stock_movements`, `production_batches`, `material_consumptions` in the delta (each has `sync_seq`), folding their max into the cursor. RLS scopes each to the tenant.
- [x] Test — initial pull returns the tenant's stock/production rows; a delta after one new movement returns only it; a neighbour's rows never appear. → PASS.
- [x] **Commit** — `feat: stream stock and production rows in the delta pull`.

> Push support for these types is deferred (see Scope); pull is extended now so an offline client can display stock/production.

---

## Task 12: RBAC coverage

**Files:** test `StockProductionRbacTest.php`

- [x] Table-driven: for **every** stock/production endpoint (raw-material create, stock-movement record, production create, `GET /stock`, `GET /production`), owner ✓ / admin ✓ / salesman ✗ (403) / accountant ✗ (403). This is the whole-module gate — the one place the "no salesman/accountant access at all" rule is proven. → PASS.
- [x] **Commit** — `test: cover stock/production RBAC (owner/admin only)`.

---

## Task 13: DB-level RLS proof

**Files:** test `Tenancy/StockRlsTest.php`

- [x] Mirror `KhataRlsTest` (query builder, app layer bypassed): a neighbour's `raw_materials`/`stock_movements`/`production_batches`/`material_consumptions` are invisible under `switchTo(mine)`; a mismatched-`business_id` insert is rejected by `WITH CHECK`; a tenant sees its own rows. → PASS.
- [x] **Commit** — `test: prove stock/production RLS with the app layer bypassed`.

---

## Task 14: Cross-tenant leak cases

**Files:** `Tenancy/CrossTenantLeakTest.php` (modified)

- [x] Append: B's stock never in A's `GET /stock`; A recording a movement for B's material → 404; A creating a batch consuming B's material → 404 and B's stock provably unchanged (`withoutGlobalScopes()` — the request pins tenant to A). → PASS.
- [x] **Commit** — `test: extend cross-tenant leak suite with stock/production cases`.

---

## Task 15: Full suite, docs, close-out

**Files:** `backend/README.md`, this plan.

- [x] **Full suite** — `php artisan test`: green. Baseline entering this slice is **171** (end of khata); every task only adds tests.
- [x] **README** — a "Stock & Production API" section: route/role table (owner/admin only), the signed-movement/on-hand rule, the append-only + `adjust`-to-correct rule, the batch-draws-stock-down behaviour and the `production_batch_id` trace link, negative-stock-allowed, and idempotency by `(business_id, uuid)`.
- [x] **Close-out** — tick every checkbox, add a status table (task → commit) and a Known Gaps section (offline push for these types, batch void, production cost write-back, packed inventory — all deferred by design; PgBouncer unchanged).
- [x] **Commit** — `docs: document the stock & production API and close out the plan`.

---

## Self-Review Notes

**PRD coverage** — §7 RBAC (owner/admin gate) → Tasks 4, 5, 6, 9, 12; §10 `RawMaterial`/`StockMovement`/`ProductionBatch`/`MaterialConsumption` → Tasks 1, 2, 8; §10 on-hand by aggregation → Tasks 3, 7; isolation (RLS + BelongsToTenant) → all model tasks + 13, 14.

**Deliberate design decisions:**
- **Signed `qty` + descriptive `kind`, sign derived server-side** — `Σ qty` is the on-hand total and an `out` can never raise stock. Storing an unsigned magnitude + separate direction would let the two disagree.
- **Append-only stock ledger, `adjust` to correct** — same rationale as khata; a physical recount is a new `adjust` movement, not an edit, so history and offline replay are preserved.
- **Batch completion writes `out` movements** — production draws stock down through the *same* ledger `GET /stock` reads, so on-hand is never a separate number that can drift from consumption. The `production_batch_id` link makes each draw-down traceable.
- **Negative stock allowed** — over-consumption is recorded and flagged, not blocked (PRD §8 "soft-block, never data loss"); hard-blocking would also break offline.
- **Owner/admin-only, reads included** — the one place this slice departs from catalog/khata's open reads, straight from the PRD §7 matrix.
- **`uuid` columns now, push later** — mirrors the catalog adding sync columns before the endpoint; pull ships in this slice, push for these types is a clean later addition.

**Known risk unchanged:** PgBouncer is not configured; the suite proves RLS/`SET LOCAL` against Postgres directly, not transaction pooling in situ.

**Test-count target:** 171 (baseline) + roughly 3+4+4+4+4+5+3+5+3+4+3+4 across Tasks 1–14 → **~220 passing**. A materially lower number means tasks were skipped.

---

## Close-out (2026-07-18) — COMPLETE

All 15 tasks landed; **231 tests pass** (535 assertions). Baseline entering this
slice was 174 (khata + Task 1's RawMaterial model); the slice added 57.

| Task | Description | Commit |
|---|---|---|
| 1 | RawMaterial model, migration, factory | `897b732` |
| 2 | StockMovement append-only signed ledger | `5418336` |
| 3 | StockService (exact on-hand, reorder flag) | `8529f23` |
| 4 | StockPolicy (owner/admin gate) | `4ed0366` |
| 5 | RawMaterial CRUD + archive/restore | `a30491a` |
| 6 | StockMovement record (signed kinds) | `ea58c33` |
| 7 | Stock reads (summary + ledger) | `73f783c` |
| 8 | ProductionBatch + MaterialConsumption models | `468909d` |
| 9 | Production batch create (draws stock down) | `a1fec99` |
| 10 | Production reads | `2d2d61d` |
| 11 | Sync pull streams stock & production | `4e29241` |
| 12 | RBAC coverage (owner/admin only) | `6c5537a` |
| 13 | DB-level RLS proof | `2e173e5` |
| 14 | Cross-tenant leak cases | `b880ff0` |
| 15 | Full suite, docs, close-out | this commit |

**One deviation from the plan, by design.** Task 11 said the pull scopes the four
new tables by RLS/tenant only. Shipped stricter: the stock/production slice of the
pull is also gated on `StockPolicy::manage()`, so a salesman's/accountant's pull
withholds those rows (khata rows still flow). Streaming stock to a role with no
stock access would contradict PRD §7's "reads included" and the project's
defense-in-depth rule, so the app layer withholds by role over RLS's tenant scope.
Covered by `SyncPullStockTest::it withholds stock rows from a salesman pull`.

## Known Gaps (deferred by design)

- **Offline `sync/push` for `stock_movement`/`production_batch`** — these are
  owner/admin facility operations, typically online. Pull carries the deltas now;
  the `uuid` columns exist so push drops in cleanly later (as the catalog did).
- **Voiding/correcting a production batch** — a batch is create-only in v1. A stock
  miscount is fixed with an `adjust` movement; reversing a wrong batch's consumption
  + output is deferred.
- **Actual cost-per-kg write-back from production** (PRD Phase 3) —
  `products.base_cost_per_kg` stays the catalog reference; production does not yet
  compute or write back a cost.
- **Finished-goods packed inventory** (PRD Phase 3) — a batch records `output_kg`;
  it does not create packed stock.
- **PgBouncer unchanged** — still not configured; the suite proves RLS/`SET LOCAL`
  against Postgres directly (port 5432), not transaction pooling in situ.
