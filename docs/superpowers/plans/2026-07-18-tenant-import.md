# Tenant Import (Excel/CSV Onboarding) Implementation Plan

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An operator-run `tenant:import` Artisan command that ingests a shop's **customers (with opening outstanding)** and **raw materials (with current stock)** from CSV into a tenant, idempotently, so its khata continues seamlessly.

**Architecture:** A format-agnostic `TenantImporter` service (header-keyed row arrays → idempotent DB writes, returning an `ImportReport`) is the core; a thin `CsvReader` (native `fgetcsv`, no new dependency) feeds it, and the `tenant:import` command wires parsing → tenant context → ingestion → a printed report. Re-runs are safe because each row gets a **deterministic UUIDv5** natural key that reuses the existing `(business_id, uuid)` unique constraints — no schema change. Opening balance → `Customer.opening_balance`; opening stock → one `in` `StockMovement`.

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL (RLS), `ramsey/uuid` (bundled), Pest.

**Design source:** `docs/superpowers/specs/2026-07-18-tenant-import-design.md` (approved). PRD §17 (Migration / Tenant #1).

**Depends on:** the catalog/khata/stock slices — `Customer` (`opening_balance`), `RawMaterial`, `StockMovement`, `StockService`, `Membership`, `TenantContext::switchTo`, and the `(business_id, uuid)` unique constraints all pre-exist. Independent of Billing/Superadmin.

---

## Scope

**In scope:** `CsvReader`; `ImportReport`; `TenantImporter` (`importCustomers`, `importRawMaterials`) with deterministic-UUID idempotency, opening-balance/opening-stock rules, per-row validation, continue-on-error, dry-run; the `tenant:import` command; cross-tenant safety + idempotency coverage.

**Out of scope** (per spec §1): `.xlsx` parsing; catalog import from a sheet; daily sales backfill; self-serve HTTP upload.

---

## File Structure

```
backend/
  app/
    Import/
      CsvReader.php          (new — fgetcsv → iterable of header-keyed rows)
      ImportReport.php       (new — created/updated/skipped/errors value object)
      TenantImporter.php     (new — importCustomers, importRawMaterials)
    Console/Commands/
      TenantImportCommand.php (new — 'tenant:import {business_id} {type} {path} {--dry-run}')
  tests/
    Unit/
      CsvReaderTest.php
      TenantImporterCustomersTest.php
      TenantImporterMaterialsTest.php
    Feature/
      Import/TenantImportCommandTest.php
    fixtures/import/
      customers.csv
      raw-materials.csv
      customers-with-errors.csv
  README.md                  (modified — Tenant Import section)
```

**Conventions inherited (not re-derived):** models use `BelongsToTenant` (business_id stamped from `app('tenant.id')` once a tenant is switched in); `created_by` is not fillable (set directly); tenant-table test setup uses `Model::on('pgsql_migrate')`; `TenantContext::switchTo($id)` sets `app.current_tenant`; exact decimal **string** money/quantity assertions.

---

## Task 1: CsvReader

**Files:** `app/Import/CsvReader.php`, fixtures `tests/fixtures/import/customers.csv`, test `tests/Unit/CsvReaderTest.php`

- [x] **Fixture `customers.csv`** — header `name,village,phone,opening_balance` then rows:
  ```csv
  name,village,phone,opening_balance
  Ram Traders,Bagru,9990001111,250.00
  Shyam Stores,Sanganer,,0
  ```
- [x] **`CsvReader::rows(string $path): iterable`** — open with `fopen($path, 'r')` (throw `RuntimeException` if false); read the first line as the header via `fgetcsv`; then for each subsequent `fgetcsv` row, `array_combine($header, $row)` and `yield` it, trimming each header and value. Skip a fully-blank trailing line (`fgetcsv` returns `[null]`). Close the handle in a `finally`.
- [x] **Test** — `iterator_to_array((new CsvReader())->rows($fixture))` yields 2 assoc rows; `rows[0]['name'] === 'Ram Traders'`, `rows[0]['opening_balance'] === '250.00'`, `rows[1]['phone'] === ''`; a missing path throws `RuntimeException`. → PASS.
- [x] **Commit** — `feat: add CsvReader header-keyed row iterator`.

Native `fgetcsv` — no new dependency. Header + row iteration is all the importer needs.

---

## Task 2: ImportReport

**Files:** `app/Import/ImportReport.php`, test `tests/Unit/ImportReportTest.php`

- [x] **`ImportReport`** — public ints `$created = 0`, `$updated = 0`, `$skipped = 0`; `array $errors = []`. Methods: `addError(int $row, string $message): void` (push `['row' => $row, 'message' => $message]` and `$this->skipped++`); `hasErrors(): bool` (`$this->errors !== []`); `summaryLine(): string` (`"Created: {$created}  Updated: {$updated}  Skipped: {$skipped}"`).
- [x] **Test** — a fresh report is all zeros; `addError(7, 'bad')` makes `skipped === 1`, `errors[0] === ['row' => 7, 'message' => 'bad']`, `hasErrors()` true; `summaryLine()` renders the counts. → PASS.
- [x] **Commit** — `feat: add ImportReport value object`.

---

## Task 3: TenantImporter — customers

**Files:** `app/Import/TenantImporter.php`, test `tests/Unit/TenantImporterCustomersTest.php`

- [x] **Class scaffolding** — `const NAMESPACE_UUID = '6f9619ff-8b86-d011-b42d-00c04fc964ff';` (a fixed app namespace). `private function norm(?string $s): string { return preg_replace('/\s+/', ' ', trim((string) $s)); }` (lowercase too: `mb_strtolower`). `private function keyUuid(string $businessId, string ...$parts): string { return (string) \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::fromString(self::NAMESPACE_UUID), $businessId.'|'.implode('|', $parts)); }`
- [x] **`importCustomers(string $businessId, iterable $rows, bool $dryRun): ImportReport`** — wrap in a transaction that always rolls back on `$dryRun` (see step); iterate rows with a 1-based index:
  - Validate: `name = norm($row['name'] ?? '')`; if empty → `addError($i, 'name is required')`, continue. `opening = $row['opening_balance'] ?? ''`; if `$opening !== '' && ! is_numeric($opening)` → `addError($i, 'opening_balance must be a number')`, continue; if numeric and `< 0` → `addError($i, 'opening_balance must be >= 0')`, continue.
  - `$uuid = $this->keyUuid($businessId, 'customer', mb_strtolower($name), mb_strtolower($this->norm($row['village'] ?? '')));`
  - `$existing = Customer::where('uuid', $uuid)->first();` (tenant-scoped — the caller has switched in). Build attributes `['name' => $name, 'village' => norm(village) ?: null, 'phone' => norm(phone) ?: null, 'opening_balance' => $opening === '' ? '0.00' : $opening]`.
  - If `$existing` → `$existing->update($attrs)`, `report.updated++`; else `Customer::create(['uuid' => $uuid] + $attrs)` (business_id stamped by `BelongsToTenant`), `report.created++`.
  - **Dry-run:** run the body inside `DB::transaction(function () { ... throw new DryRunRollback; })` and catch that private marker exception so nothing persists; the report is built before the throw. (Or use `DB::beginTransaction()` / `DB::rollBack()` in a `finally` when `$dryRun`.)
- [x] **Test** (switch tenant in the test: `TenantContext::switchTo($business->id)`) —
  - importing 2 valid rows → `created === 2`, and `Customer::on('pgsql_migrate')->where('business_id',$b)->count() === 2` with `opening_balance` stored as `'250.00'`;
  - a **re-run** of the same rows → `created === 0`, `updated === 2`, count still 2 (deterministic uuid, no duplicates);
  - a row with empty name → `skipped === 1`, `errors[0]['row']` correct, no customer for it;
  - a non-numeric `opening_balance` → skipped;
  - `dryRun = true` on fresh rows → `created === 2` in the report but `Customer::...->count() === 0` (nothing persisted). → PASS.
- [x] **Commit** — `feat: import customers with opening balance, idempotent by derived uuid`.

`opening_balance` → `Customer.opening_balance`, so `outstanding = opening_balance + Σ sales − Σ payments` continues the shop's बाकी from day one.

---

## Task 4: TenantImporter — raw materials + opening stock

**Files:** `app/Import/TenantImporter.php` (add method), test `tests/Unit/TenantImporterMaterialsTest.php`

- [x] **`importRawMaterials(string $businessId, iterable $rows, bool $dryRun, int $ownerUserId): ImportReport`** — same transaction/dry-run wrapper. Per row (1-based):
  - Validate: `name` required (else error+skip); `unit = norm($row['unit'] ?? '') ?: 'kg'`; if `unit` not in `['kg','litre','piece','gram','ml','packet']` → error+skip; `reorder_level` numeric ≥ 0 if present else error+skip; `opening_stock` numeric ≥ 0 if present else error+skip.
  - Material uuid: `keyUuid($businessId, 'material', mb_strtolower($name))`. Upsert `RawMaterial` (`['uuid' => $mUuid, 'name' => $name, 'unit' => $unit, 'reorder_level' => $reorder === '' ? null : $reorder]`); create → `created++`, update → `updated++`. Capture `$material`.
  - **Opening stock:** if `opening_stock !== '' && (float) opening_stock > 0`: `$sUuid = keyUuid($businessId, 'opening-stock', mb_strtolower($name));` `$mv = StockMovement::where('uuid', $sUuid)->first();` if exists → `$mv->update(['qty' => $opening_stock])` (correctable single entry); else create a new `StockMovement` with `['uuid' => $sUuid, 'raw_material_id' => $material->id, 'movement_date' => now()->toDateString(), 'kind' => 'in', 'qty' => $opening_stock, 'note' => 'Opening stock (import)']`, `created_by = $ownerUserId` (set directly — not fillable). (Movement create/update does not change the material create/update counters.)
- [x] **Test** (`TenantContext::switchTo`) —
  - importing a material with `opening_stock = '100.000'` creates the `RawMaterial` and one `in` `StockMovement`, and `StockService::onHandFor($material) === '100.000'`;
  - a **re-run** → material `updated`, on-hand still `'100.000'` (opening movement not doubled);
  - a re-run with `opening_stock = '80.000'` → on-hand `'80.000'` (the single opening movement is corrected, not stacked);
  - a material with no `opening_stock` creates no movement (`onHandFor === '0.000'`);
  - a bad `unit` row is skipped and reported. → PASS.
- [x] **Commit** — `feat: import raw materials with a single correctable opening-stock movement`.

One deterministic opening movement per material keeps opening stock a correctable entry, not an ever-growing pile of `in`s — that is why a re-import fixes a miscount instead of double-counting.

---

## Task 5: The tenant:import command

**Files:** `app/Console/Commands/TenantImportCommand.php`, fixtures `raw-materials.csv` + `customers-with-errors.csv`, test `tests/Feature/Import/TenantImportCommandTest.php`

- [x] **Fixtures** — `raw-materials.csv` (`name,unit,reorder_level,opening_stock` with a Besan/100.000 row and an Oil/litre row) and `customers-with-errors.csv` (one good row, one with an empty name).
- [x] **Command** — signature `protected $signature = 'tenant:import {business_id} {type} {path} {--dry-run}';`. `handle()`:
  - `$business = Business::find($this->argument('business_id'));` if null → `$this->error('Business not found.'); return self::FAILURE;`
  - `$type = $this->argument('type');` if not in `['customers','raw-materials']` → error + `FAILURE`.
  - `$path = $this->argument('path');` if `! is_readable($path)` → `$this->error('File not readable.'); return self::FAILURE;`
  - `TenantContext::switchTo($business->id);`
  - `$owner = Membership::where('business_id', $business->id)->where('role', 'owner')->first();` if null → error + `FAILURE`.
  - `$rows = (new CsvReader())->rows($path);` `$dry = (bool) $this->option('dry-run');`
  - `$report = $type === 'customers' ? $importer->importCustomers($business->id, $rows, $dry) : $importer->importRawMaterials($business->id, $rows, $dry, $owner->user_id);` (pass `$importer` via `app(TenantImporter::class)`). Note: `CsvReader` yields a generator — materialize with `iterator_to_array($rows)` before passing if the importer iterates twice; it iterates once, so the generator is fine.
  - Print `$this->info($report->summaryLine());` then each error as `$this->warn("Row {$e['row']}: {$e['message']}");`.
  - `return $report->hasErrors() ? self::FAILURE : self::SUCCESS;` (valid rows still applied; non-zero exit flags the errors to a script).
- [x] **Test** (Pest, `artisan(...)` helper) —
  - `$this->artisan('tenant:import', ['business_id' => $b->id, 'type' => 'customers', 'path' => $customersFixture])->assertExitCode(0);` then `Customer::on('pgsql_migrate')->where('business_id',$b->id)->count() === 2`;
  - importing `raw-materials.csv` creates the materials and Besan's on-hand is `'100.000'`;
  - a `customers-with-errors.csv` import exits **1**, still creates the one good customer, and warns on the bad row;
  - a missing file / unknown business / bad type each exit `1` and write nothing;
  - **cross-tenant safety:** create business B with a customer; run the import for business A; B's customers are unchanged (`Customer::on('pgsql_migrate')->where('business_id',$B->id)->count()` unchanged). → PASS.
- [x] **Commit** — `feat: add tenant:import Artisan command`.

The command must create the owner membership for the target business in each test (a real tenant always has one). Read how existing tests build a business + owner membership and mirror it.

---

## Task 6: Full suite, docs, close-out

**Files:** `backend/README.md`, this plan.

- [x] **Full suite** — `php artisan test`: green. Baseline entering this slice is the Superadmin end state; every task only adds tests.
- [x] **README** — a "Tenant Import" section: the `tenant:import {business_id} {type} {path} [--dry-run]` usage, the expected CSV columns for each type, the opening-balance/opening-stock semantics, the deterministic-uuid re-run safety, and the continue-on-error + non-zero-exit contract.
- [x] **Close-out** — tick every checkbox, add a status table (task → commit) and a Known Gaps section (`.xlsx` reader, catalog import, sales backfill, self-serve HTTP upload — all deferred; PgBouncer unchanged).
- [x] **Commit** — `docs: document tenant import and close out the plan`.

---

## Self-Review Notes

**Spec coverage** — §3 command → Task 5; §4 ingestion core → Tasks 3, 4; §4.1 customers → Task 3; §4.2 materials/opening stock → Task 4; §5 deterministic uuid → Tasks 3, 4 (`keyUuid`); §6 report → Task 2; §7 isolation/dry-run → Tasks 3, 4 (dry-run), 5 (cross-tenant); §8 testing → every task; CSV adapter → Task 1.

**Deliberate design decisions** (spec §9): format-agnostic service + thin CSV adapter; deterministic UUIDv5 over the existing unique; one correctable opening-stock movement; continue-on-error + dry-run; operator-run Artisan; normal RLS connection.

**Type/name consistency** — `CsvReader::rows()`, `ImportReport` (`created/updated/skipped/errors`, `addError`, `hasErrors`, `summaryLine`), `TenantImporter::importCustomers(businessId, rows, dryRun)` / `importRawMaterials(businessId, rows, dryRun, ownerUserId)`, and `keyUuid(businessId, ...parts)` are used consistently across Tasks 1–5. `StockService::onHandFor` is the existing method (returns a scale-3 string).

**Known risk unchanged:** PgBouncer is not configured; the suite proves RLS/`SET LOCAL` against Postgres directly, not transaction pooling in situ.

**Test-count target:** Superadmin end state + roughly 3(T1)+3(T2)+5(T3)+5(T4)+5(T5) → **~+21 passing**. A materially lower number means tasks were skipped.

---

## Close-out (2026-07-18) — COMPLETE

All tasks implemented, each with its own passing tests; full suite green at
**253 passed / 601 assertions** (+22 from this slice: 2·T1 + 3·T2 + 5·T3 + 5·T4 + 7·T5).

| Task | Deliverable | Commit |
|---|---|---|
| 1 | `CsvReader` + fixture + test | `feat: add CsvReader header-keyed row iterator` |
| 2 | `ImportReport` value object + test | `feat: add ImportReport value object` |
| 3 | `TenantImporter::importCustomers` + tests | `feat: import customers with opening balance, idempotent by derived uuid` |
| 4 | `TenantImporter::importRawMaterials` + opening stock + tests | `feat: import raw materials with a single correctable opening-stock movement` |
| 5 | `tenant:import` command + fixtures + feature test | `feat: add tenant:import Artisan command` |
| 6 | README section + close-out | `docs: document tenant import and close out the plan` |

**Deviation from the plan (deliberate, behaviour unchanged):** tenant context is owned by
the **importer**, not the command. The plan (Task 5) had the command call `switchTo` alone,
but `SET LOCAL app.current_tenant` only takes effect inside an open transaction, and the
`BelongsToTenant` app scope + `business_id` stamping read `app('tenant.id')` — neither of
which `switchTo` sets. So `TenantImporter` opens one transaction per import, calls
`TenantContext::switchTo()` **and** binds `app('tenant.id')` (the existing `TenantAwareJob`
pattern for non-HTTP tenant work), then commits — or, on `--dry-run`, rolls back. The command
still resolves the owner membership under its own short `switchTo` transaction (memberships are
RLS-scoped to `current_tenant`). All behaviour the spec/tests specify is unchanged.

### Known Gaps (deferred, per spec §1)

- **`.xlsx` parsing** — CSV only; operators export sheets to CSV first. A future `XlsxReader`
  can feed the same `TenantImporter` (format-agnostic core) with no importer change.
- **Catalog import from a sheet** (products/packs) — out of scope; catalog is seeded via templates.
- **Daily sales / khata-transaction backfill** — only *opening* balances/stock are imported,
  not historical sales or payments.
- **Self-serve HTTP upload** — operator-run Artisan only; no tenant-facing upload endpoint.
- **PgBouncer unchanged** — the suite proves RLS/`SET LOCAL` against Postgres directly, not
  transaction pooling in situ (pre-existing project-wide gap).
