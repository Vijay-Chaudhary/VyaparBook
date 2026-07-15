# Tenant Catalog — Design Spec

**Status:** approved 2026-07-15
**Slice:** Phase 1, module 2 (follows the tenancy & auth core)
**Supersedes for this slice:** PRD §10's Django sketch of `Product` / `PackSize` / `ProductPack`. The domain intent stands; the Eloquent/migration model below is what gets built.

---

## 1. Purpose & scope

PRD §6 calls the tenant-configurable catalog "correctness-critical" and states the rule this slice exists to honour: **nothing about products is hardcoded.** A namkeen maker sells Sev in 15 pack sizes; a spice seller sells Haldi in 100g/200g/500g. Both are ordinary tenant data. Sales, khata, stock and production stay domain-generic on top.

**In scope:**

- `Product`, `PackSize`, `ProductPack` models, migrations, factories
- RLS policies + `BelongsToTenant` app-level scope on all three (defense in depth, per CLAUDE.md)
- `GET /catalog` aggregate read; granular CRUD for each resource
- Archive/restore
- Template seeding for onboarding (PRD §5 step 3): Namkeen, Sweets, Spices, Blank

**Out of scope** (each its own later slice):

- Excel/CSV import (PRD §5 step 4) — that is customers and opening balances, not catalog
- `GET /sync?since=` delta endpoint (PRD §9) — this slice adds the columns sync will need, not the endpoint
- Any frontend. No Next.js app exists yet.
- Actual cost-per-kg from production (PRD Phase 3) — `base_cost_per_kg` is the reference field it will later populate

**Depends on:** the tenancy/auth core — `SetTenantContext`, `RequireTenant`, `BelongsToTenant`, and the `pgsql`/`pgsql_migrate` connection split. (No catalog code touches `TokenService`; the onboarding flow in §4 relies on it, but only through the existing `POST /businesses`.)

---

## 2. Data model

All DDL runs through `pgsql_migrate`; the restricted `vyaparbook_app` role has no DDL rights.

```php
// products
$table->uuid('id')->primary();
$table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
$table->string('name_hi', 120);
$table->string('name_en', 120)->nullable();
$table->decimal('base_cost_per_kg', 10, 2)->nullable();
$table->timestamp('archived_at')->nullable();
$table->unsignedInteger('version')->default(1);
$table->timestamps();

// pack_sizes
$table->uuid('id')->primary();
$table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
$table->string('label', 40);
$table->decimal('weight_kg', 8, 3);
$table->boolean('in_dropdown')->default(true);
$table->timestamp('archived_at')->nullable();
$table->unsignedInteger('version')->default(1);
$table->timestamps();
$table->unique(['business_id', 'label']);

// product_packs
$table->uuid('id')->primary();
$table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
$table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
$table->foreignUuid('pack_size_id')->constrained('pack_sizes')->cascadeOnDelete();
$table->decimal('default_sell_price', 10, 2);
$table->decimal('default_cost_price', 10, 2)->nullable();
$table->timestamp('archived_at')->nullable();
$table->unsignedInteger('version')->default(1);
$table->timestamps();
$table->unique(['business_id', 'product_id', 'pack_size_id']);
```

Money is `decimal(10,2)`, cast `decimal:2` on the models — never float, so rupee arithmetic cannot drift. `weight_kg` carries 3 decimals so 100g is exactly `0.100`.

### Units: kg is canonical

`weight_kg` is the single canonical unit; `PackSize.label` is free text for display. A spice seller's "100g" is `label = '100g'`, `weight_kg = 0.100` — the label shows, the number does the math.

This is a deliberate, bounded exception to "nothing is hardcoded". Every vertical the PRD names (namkeen, sweets, spices) sells by weight, and PRD Phase 3 wants cost-per-kg reporting, which needs a comparable unit. A `unit` dimension with conversion would add rounding-bug surface to money math before anything requires it. If a non-weight vertical (pieces, litres) ever lands, the fix is one migration — cheap, and deferred until real.

### Names

`name_hi` is required, `name_en` optional (nullable, default `NULL`). Reads fall back to `name_hi` when `name_en` is absent, so the fallback tracks edits automatically and cannot go stale. This follows PRD §10 and §16 (Hindi default). It does assume Hindi-primary; an English-first tenant would type English into `name_hi`. Accepted for now — every planned tenant is Hindi-first, and renaming to a language-neutral `name` is a cheap later migration.

### Two flags that look alike but are not

- `archived_at` — retired. Hidden everywhere by default; the row stays forever so historical sales resolve.
- `in_dropdown` (PackSize only) — still actively sold, just not one of the handful of sizes worth showing on the sale screen. Purely a UI hint.

A tenant with 15 pack sizes typically wants ~6 in the dropdown and none of them archived.

### Archiving is never cascaded

A `ProductPack` is **effectively archived** when it, its `Product`, or its `PackSize` is archived. This is evaluated at read time; archiving a product does **not** write `archived_at` onto its 15 product-packs.

The alternative — cascading the write — destroys information and makes restore ambiguous: after un-archiving Sev, should a pack that was *individually* archived beforehand come back? Under read-time evaluation the question does not arise. Each row records only its own state, archive and restore are symmetric, and no write touches rows the user did not name.

`GET /catalog` therefore omits effectively-archived product-packs, not merely directly-archived ones.

### Why `PackSize` stays a separate table

Pack sizes are shared across products: tenant #1 has 3 products × 15 sizes. Collapsing them into `product_packs` would duplicate "500g" 45 times and make renaming a bulk update. `in_dropdown` is also a property of the size itself, not of a product-size pairing.

---

## 3. Isolation

All three tables get the flat policy that the tenancy spec anticipated for every future domain table — identical on each, with no user-branch (unlike `memberships`, catalog is only ever read with a tenant already selected):

```sql
ALTER TABLE products ENABLE ROW LEVEL SECURITY;
ALTER TABLE products FORCE ROW LEVEL SECURITY;
CREATE POLICY products_isolation ON products
  USING      (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
  WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid);
```

All three models use `BelongsToTenant` — its first real consumer, and the app-level half of the defense-in-depth rule in CLAUDE.md. The trait stamps `business_id` on create from `app('tenant.id')` and narrows every query.

Version bumping lives in a separate `HasVersion` trait (increment on `updating`), not inside `BelongsToTenant` — unrelated concerns, and future non-tenant models may want versioning.

Because `SetTenantContext` has already opened the request transaction and set `app.current_tenant`, no catalog code calls `TenantContext::switchTo()`. That helper exists for endpoints acting on a business other than the caller's active `tid` (business creation, invite accept); the catalog never does.

---

## 4. Templates

Templates are PHP files in `database/catalog_templates/` — `namkeen.php`, `sweets.php`, `spices.php`. They are code: versioned in git, reviewed in PRs, and a new Phase 3 vertical is a new file with no migration. `"blank"` is a valid choice with no file and no rows.

Rows are inserted directly into the tenant's own tables, so "every tenant edits freely" (§6) needs no extra machinery — a seeded row is an ordinary row from the moment it exists.

```php
// database/catalog_templates/namkeen.php
return [
    'label' => 'Namkeen / Snacks',
    'products' => [
        'sev' => ['name_hi' => 'सेव', 'name_en' => 'Sev', 'base_cost_per_kg' => '120.00'],
        // …senvda, mix
    ],
    'pack_sizes' => [
        '100g' => ['label' => '100g', 'weight_kg' => '0.100', 'in_dropdown' => true],
        // …15 sizes for tenant #1
    ],
    'product_packs' => [
        ['product' => 'sev', 'pack' => '100g', 'default_sell_price' => '20.00'],
    ],
];
```

Keys (`'sev'`, `'100g'`) are template-local identifiers resolved to UUIDs during insert, so nothing depends on display text.

`CatalogTemplateService::apply(string $slug, string $businessId): void` reads the file and inserts inside the request's existing transaction.

**Seeding is guarded, not idempotent.** If the tenant already has any product, it returns 409. `unique(business_id, label)` on `pack_sizes` would otherwise surface a raw constraint violation as a 500, and PRD §5 frames seeding as a one-time onboarding step. A 409 states that rule; a duplicate-key crash does not.

The onboarding flow needs no special case: `POST /businesses` already returns a token whose `tid` is the new business with `role: owner`, so the client can call `POST /catalog/seed` immediately.

---

## 5. Endpoints & RBAC

All routes sit behind `['auth:api', 'tenant.context', 'require.tenant']`.

```
GET    /api/v1/catalog                    # aggregate read — any role
POST   /api/v1/catalog/seed               # apply a template — owner/admin

POST   /api/v1/products                   # owner/admin
PATCH  /api/v1/products/{id}              # owner/admin
DELETE /api/v1/products/{id}              # archive — owner/admin
POST   /api/v1/products/{id}/restore      # un-archive — owner/admin
       …identical shape for /pack-sizes and /product-packs
```

`GET /catalog` returns products with packs and prices nested — one payload the PWA can cache in a single round trip, which is what PRD §9's cache-first design needs on a 2G link. Effectively-archived rows (§2) are excluded; `?include_archived=1` returns them for the management view.

Pack sizes with `in_dropdown = false` **are** included. The flag is a UI hint the client applies when rendering the sale screen's dropdown, not a filter on the payload — the sizes are still sellable and the cache must hold them.

`DELETE` meaning *archive* is the one deliberate bend in REST. It reads naturally and keeps the append-only ledger intact.

**RBAC** mirrors `InvitePolicy`: a `CatalogPolicy` reading `app('tenant.role')`, with `manage()` true for `owner`/`admin` only — PRD §7's "Manage catalog & prices" row. Reads stay open to all four roles: a salesman cannot sell without the catalog, and an accountant needs it to read a khata. Role comes from the verified membership in the token, never from the client.

### One rule, one home

"Suggest `base_cost_per_kg × weight_kg`" applies in two places — when a template omits a cost, and when someone `POST`s a product-pack without one. It lives in `CatalogService::suggestedCostPrice(Product $product, PackSize $pack): ?string`, called from both, so they cannot drift.

`default_cost_price` is stored per pack and authoritative once set; the suggestion only fills a blank at creation. Packaging and labour do not scale linearly with weight — a 100g pouch genuinely costs more per kg than a 1kg bag — so the tenant must be able to override. `base_cost_per_kg` remains on `Product` as the reference figure Phase 3's production costing will populate.

---

## 6. Error handling

| Situation | Response | Source |
|---|---|---|
| No tenant selected | 400 | `RequireTenant` (existing) |
| Not a member of `tid` | 403 | `SetTenantContext` (existing) |
| Salesman/accountant attempting a write | 403 | `CatalogPolicy` |
| Row belongs to another tenant | 404 | RLS (see below) |
| Seeding a non-empty catalog | 409 | `CatalogTemplateService` |
| Bad input | 422 | Form request validation |

**Cross-tenant access returns 404, not 403, and that is deliberate.** RLS hides other tenants' rows outright, so `findOrFail` raises a genuine "no such row". This is also the better answer: a 403 would confirm the row exists, leaking that a competitor's product ID is real. Non-disclosure falls out of the isolation design rather than needing to be remembered.

**`Rule::unique('pack_sizes', 'label')` needs no tenant clause.** Validation runs inside the request transaction with `app.current_tenant` set, so RLS has already narrowed the table to this tenant. This must carry a code comment — it looks like a missing scope to any reader who does not know the RLS layer is underneath.

Note the interaction with archiving: an archived "500g" still occupies the unique label. The correct move is restore, not duplicate. Validation should say so rather than returning a bare "already taken".

---

## 7. Testing

Pest, following the conventions the tenancy slice established: no `RefreshDatabase` (see that spec's §7 for why it is unusable here), `RefreshesTenantDatabase` applied via `tests/Pest.php`, and `Model::on('pgsql_migrate')` for setup rows that must bypass RLS.

- `tests/Unit/CatalogServiceTest.php` — `suggestedCostPrice` math, including the 100g rounding case
- `tests/Unit/HasVersionTraitTest.php` — version starts at 1, bumps on update, unchanged on read
- `tests/Feature/Catalog/CatalogCrudTest.php` — happy paths for all three resources
- `tests/Feature/Catalog/CatalogRbacTest.php` — salesman reads 200, writes 403; owner and admin write 200
- `tests/Feature/Catalog/CatalogArchiveTest.php` — archived rows drop out of `GET /catalog`, return under `?include_archived=1`, stay resolvable by ID; restore works; an archived label blocks re-creation; archiving a product hides its packs from `GET /catalog` **without** writing `archived_at` on them, and restoring the product brings back exactly the packs that were not individually archived
- `tests/Feature/Catalog/CatalogTemplateTest.php` — namkeen seeds the expected rows, owned by the caller's tenant and editable; a second seed returns 409; `"blank"` is a no-op
- `tests/Feature/Tenancy/CatalogRlsTest.php` — DB-level proof mirroring `MembershipRlsTest`: `WITH CHECK` rejects inserting a product for another tenant even with the app layer bypassed

**Cross-tenant cases extend the existing `CrossTenantLeakTest`** rather than opening a new file — that suite is the single place isolation is proven, and splitting it makes it easy to believe coverage exists where it does not. Three cases: business A's owner cannot read, patch, or archive business B's product (404 each).

---

## 8. Decisions & rationale

Recorded so the next reader does not re-litigate them:

1. **Templates included in this slice.** They are what make a new tenant sellable in under five minutes (§5); without them a tenant starts empty and hand-types 45 rows.
2. **kg canonical, free-text label.** §2 above.
3. **Archive, never delete.** §9's ledger is append-only; a two-year-old sale must always resolve what was sold.
4. **`version`/`updated_at` now, `/sync` later.** Two columns today versus a backfill migration on a table with live tenant data.
5. **Aggregate read + granular writes.** §11 names `GET /catalog` literally; §7 needs per-resource RBAC and validation errors.
6. **Templates as data files, not DB rows.** A global `catalog_templates` table would let a superadmin edit templates without a deploy, but §12 does not ask for it, and it splits "what is a template" across the repo and the DB. Revisit if template editing becomes a real request.

---

## 9. Open questions

None blocking. Two items are consciously deferred rather than unresolved:

- **Language neutrality of `name_hi`** — accepted as Hindi-primary; revisit if a non-Hindi tenant appears (§2).
- **Non-weight units** — accepted as kg-only; revisit if a pieces/litres vertical appears (§2).
