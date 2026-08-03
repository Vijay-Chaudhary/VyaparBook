# Tenant Import (Excel/CSV Onboarding) — Design Spec

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

**Date:** 2026-07-18
**Status:** Approved for planning
**Parent doc:** VyaparBook PRD v2.0 (Multi-Tenant SaaS), §17 (Migration / Tenant #1), §6 (tenant-owned catalog)

## 1. Purpose & scope

Bring an existing shop's data into a tenant so its khata continues seamlessly on day one. For Tenant #1 (Shree Raj Shyama Ji) and, generically, for any future tenant, ingest **customers with their opening outstanding** and **raw materials with current stock** from the shop's spreadsheet, exported to CSV. Built as an operator-run Artisan command over a **format-agnostic ingestion service**, so a later self-serve HTTP upload or an `.xlsx` reader reuses the same core.

**In scope:**
- `TenantImporter` service — `importCustomers()` and `importRawMaterials()`, taking header-keyed row arrays and returning an `ImportReport`.
- `CsvReader` — native `fgetcsv` adapter, file → iterable of assoc rows (no new dependency).
- `ImportReport` — `{created, updated, skipped, errors[]}` value object.
- `tenant:import {business_id} {type} {path} [--dry-run]` Artisan command wiring parsing → tenant context → ingestion → report.
- **Idempotent re-runs** via deterministic UUIDv5 natural keys reusing the existing `(business_id, uuid)` unique constraints.
- Opening-balance → `Customer.opening_balance`; opening-stock → a single `in` `StockMovement`.
- Per-row validation, continue-on-error, `--dry-run`.

**Out of scope (deferred):**
- **`.xlsx` parsing** (`maatwebsite/excel`/PhpSpreadsheet) — the operator exports each sheet to CSV in v1; the format-agnostic core makes an `.xlsx` adapter a clean later addition.
- **Catalog import from a sheet** (Products/PackSizes/ProductPacks) — Tenant #1's catalog comes from the existing template seed + manual price adjust (PRD §17 step 1); a sheet-driven catalog import is a later row-type handler.
- **Daily sales history backfill** (PRD §17 step 4, explicitly optional) — heavy (per-line pack resolution, dates); a later handler on the same service.
- **Self-serve HTTP upload** — the command is operator-run in v1; the service core is the seam an upload endpoint wraps later. Any frontend.

## 2. Stack decisions for this slice

- **Backend:** Laravel (PHP 8.3) Artisan command + a service, following the existing slices.
- **CSV parsing:** native `fgetcsv` behind a small `CsvReader` — **no new Composer dependency** (keeps the minimal-deps ethos). `league/csv` was considered and rejected as unnecessary for header + row iteration.
- **Idempotency:** deterministic **UUIDv5** via `ramsey/uuid` (already bundled with Laravel).
- **Testing:** Pest — unit tests feed the service fixture row-arrays; a command test drives a fixture `.csv` end-to-end.

## 3. Interface — the Artisan command

```
php artisan tenant:import {business_id} {type} {path} [--dry-run]
```

- `business_id` — the target `Business` UUID. The command loads it (error + non-zero exit if missing).
- `type` — `customers` | `raw-materials`. Selects the ingestion method and the expected columns.
- `path` — a readable `.csv` file (error if missing/unreadable).
- `--dry-run` — validate and report what *would* happen; write nothing.

**Context setup (the command does what HTTP middleware normally would):**
- `TenantContext::switchTo($businessId)` so RLS `WITH CHECK` admits the writes and `BelongsToTenant` stamps `business_id` from the current tenant.
- Resolve the business's **owner** membership (`Membership::where(business_id)->where(role,'owner')->first()`); its `user_id` is used as `created_by` on stock movements. Error if the business has no owner (a tenant is always provisioned with one, so this is a guard, not a normal path).
- No role/policy gate — the command is operator-run; shell access is the authorization.

The command reads the file via `CsvReader`, calls the matching `TenantImporter` method with `dryRun` from the flag, and prints the `ImportReport` as a summary table (created / updated / skipped, then each error row).

## 4. Ingestion core — `TenantImporter`

Format-agnostic: it takes `iterable $rows` of header-keyed assoc arrays, never a file. This is where all validation, idempotency, and domain rules live, and where the unit tests point.

```php
public function importCustomers(string $businessId, iterable $rows, bool $dryRun): ImportReport;
public function importRawMaterials(string $businessId, iterable $rows, bool $dryRun): ImportReport;
```

- The whole run is wrapped in a `DB::transaction` (on the normal connection, tenant already switched). On `--dry-run`, the transaction is rolled back at the end so nothing persists but validation still exercises real constraints.
- **Continue-on-error:** each row is validated; a failing row is appended to `report.errors` (`{row, message}`) and skipped — never fatal. Valid rows are written. Real sheets are messy; a single bad बाकी cell must not abort 40 good customers.
- Row numbers in errors are 1-based over the data rows (header excluded), matching what the operator sees in the sheet.

### 4.1 Customers

Expected columns: `name` (required), `village`, `phone`, `opening_balance`.

- Validation: `name` non-empty (≤120); `opening_balance` numeric ≥ 0 if present (default `'0.00'`); `village` ≤80; `phone` ≤20.
- Idempotency key (§5): UUIDv5 from `business_id | 'customer' | normalized(name) | normalized(village)`.
- Upsert: if a `Customer` with that `(business_id, uuid)` exists → update `village`/`phone`/`opening_balance` (count `updated`); else create (count `created`). `opening_balance` maps straight to `Customer.opening_balance`, so `outstanding = opening_balance + Σ sales − Σ payments` continues the shop's बाकी.

### 4.2 Raw materials + opening stock

Expected columns: `name` (required), `unit` (default `'kg'`), `reorder_level`, `opening_stock`.

- Validation: `name` non-empty (≤120); `unit` in the RawMaterial whitelist (`kg|litre|piece|gram|ml|packet`); `reorder_level` numeric ≥ 0 if present; `opening_stock` numeric ≥ 0 if present.
- Material idempotency key: UUIDv5 from `business_id | 'material' | normalized(name)`. Upsert the `RawMaterial` (create/update `unit`/`reorder_level`).
- **Opening stock:** if `opening_stock > 0`, upsert **one** `in` `StockMovement` with a deterministic uuid from `business_id | 'opening-stock' | normalized(name)`, `qty = opening_stock` (positive/`in`), `movement_date = today`, `note = 'Opening stock (import)'`, `created_by = <owner>`. Because the movement's `(business_id, uuid)` is unique and deterministic, a re-run maps to the same row and **on-hand is never double-counted**. (Re-running with a *changed* opening_stock updates that one movement, keeping opening stock a single correctable entry rather than an ever-growing pile of `in`s.)

## 5. Idempotency — deterministic UUIDv5

The source rows carry no client `uuid`, yet the import must be safe to re-run (an interrupted onboarding, a corrected sheet). Instead of a random uuid per row, derive a **stable** one:

```php
$namespace = Uuid::fromString('VyaparBook fixed app namespace UUID'); // a constant in the class
$uuid = (string) Uuid::uuid5($namespace, "{$businessId}|customer|".norm($name)."|".norm($village));
```

- `norm()` lowercases and trims/collapses whitespace so trivial formatting differences don't create a second row.
- The derived uuid feeds the **existing** `(business_id, uuid)` unique constraint on `customers`/`raw_materials`/`stock_movements` — no schema change. Re-run = same uuid = upsert, not duplicate.
- This is the same idempotency guarantee the online/sync writes get from a client uuid, synthesized deterministically for a source that has none.

## 6. Report — `ImportReport`

A value object accumulated by the service and rendered by the command:

```php
final class ImportReport {
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;   // == count(errors)
    /** @var list<array{row:int, message:string}> */
    public array $errors = [];
}
```

The command prints: `Created: N  Updated: N  Skipped: N`, then one line per error (`Row 7: opening_balance must be a number`). Non-zero exit code if there were any errors (so a CI/operator script notices), even though valid rows were still applied.

## 7. Isolation & safety

- The command `switchTo`-es exactly the target business before any write, so every created row is stamped and RLS-checked to that tenant — an import to business A can never write business B's rows (proven by a cross-tenant test: seed B, import A, assert B untouched).
- `--dry-run` rolls back inside the transaction: validation runs against real constraints (unique, RLS `WITH CHECK`) but nothing persists.
- The importer uses the normal (RLS-enforced) connection, not a bypass — imports are per-tenant, not cross-tenant, so the ordinary tenant scope is exactly right.

## 8. Testing

Pest, existing conventions. Fixtures live under `tests/fixtures/import/`.

- **Unit (`TenantImporter`, fixture row-arrays):** customers created with `opening_balance`; a re-run with the same rows creates 0 / updates N (deterministic uuid — no duplicates); raw materials created with an opening `in` movement whose qty makes `StockService::onHandFor` exact; a re-run does **not** double on-hand; a changed `opening_stock` updates the single opening movement; bad rows (missing name, non-numeric balance, bad unit) are skipped and reported with the right row numbers; `--dry-run` (dryRun=true) persists nothing.
- **Feature (`tenant:import` command, fixture `.csv`):** the command imports a customers CSV into a business and exits 0 with the right report; a raw-materials CSV creates materials + opening stock; a missing file / unknown business / bad type errors with a non-zero exit; a cross-tenant safety case (import into A leaves B's rows untouched).

Baseline entering this slice is the Superadmin end state; target roughly **+20** tests.

## 9. Design decisions (rationale)

- **Format-agnostic service, thin CSV adapter** — the value (idempotency, opening-balance/stock rules, validation) is independent of the file format; keeping parsing at the edge makes an `.xlsx` reader or an HTTP upload a later adapter, not a rewrite.
- **Deterministic UUIDv5 over the existing `(business_id, uuid)` unique** — gives re-run safety with zero schema change, synthesizing for a source with no client uuid the same guarantee the sync path gets from one.
- **Opening stock is one correctable `in` movement, not an append each run** — a deterministic movement uuid means the opening quantity stays a single editable entry; re-importing a corrected count fixes it instead of stacking.
- **Continue-on-error + `--dry-run`** — real onboarding sheets have messy cells; skipping and reporting bad rows (and previewing with dry-run) beats all-or-nothing for a 40-row human file, while the transaction still gives crash-safety.
- **Operator-run Artisan, no policy gate** — PRD §17 frames this as operator migration; the service core is the seam a future owner-facing self-serve upload wraps with the usual auth.
- **Normal RLS connection, not the platform bypass** — imports are single-tenant writes; the ordinary tenant scope is the correct and safest isolation here.

**Known risk unchanged:** PgBouncer is not configured; the suite proves RLS/`SET LOCAL` against Postgres directly, not transaction pooling in situ.
