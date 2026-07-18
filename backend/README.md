# VyaparBook Backend

Laravel 11 API for VyaparBook's tenancy & auth core. No Docker — Postgres,
PgBouncer, and Redis run as native local services.

## Prerequisites

- PHP 8.3, Composer
- PostgreSQL 15+
- PgBouncer
- Redis

## One-time Postgres setup

1. Create the database and a privileged superuser role for migrations (or use
   your existing `postgres` superuser):
   ```sql
   CREATE DATABASE vyaparbook;
   ```
2. After running migrations once (`php artisan migrate --database=pgsql_migrate`,
   see below), the `vyaparbook_app` role will exist but have no password set —
   the migration creates the role but deliberately never sets a password, so no
   secret is embedded in migration history. Until you set one, the app cannot
   connect and every request fails with `password authentication failed for user
   "vyaparbook_app"`. Set it to match your `.env`:
   ```sql
   ALTER ROLE vyaparbook_app WITH PASSWORD 'change-me';
   ```
3. Create the test database. `phpunit.xml` points the suite at it so that running
   tests never wipes your development data:
   ```sql
   CREATE DATABASE vyaparbook_test;
   ```
   No grants are needed by hand — the `create_app_role` migration grants against
   whichever database it runs on, and the test suite migrates this one on its
   first run.

## PgBouncer setup

> **Status: not yet wired up** (re-verified 2026-07-15). The PgBouncer process is
> up and accepting connections on 6432, but it is still the stock package config:
> connecting through it fails with `FATAL: no such database` for both
> `vyaparbook` and `vyaparbook_test`, so nothing routes through the proxy. `.env`
> also has `DB_PORT=5432` (direct to Postgres) while `.env.example` correctly
> says `6432` — the working `.env` has drifted. The steps below are what it takes
> to close the gap; they need root. Until they are applied, treat
> `tests/Feature/Tenancy/PgBouncerPooledConnectionTest.php` as proving Postgres
> `SET LOCAL` semantics only, not PgBouncer behaviour (the test says as much).

In `/etc/pgbouncer/pgbouncer.ini`:
```ini
[databases]
vyaparbook = host=127.0.0.1 port=5432 dbname=vyaparbook
vyaparbook_test = host=127.0.0.1 port=5432 dbname=vyaparbook_test

[pgbouncer]
pool_mode = transaction
listen_addr = 127.0.0.1
listen_port = 6432
auth_type = scram-sha-256
auth_file = /etc/pgbouncer/userlist.txt
```

`pool_mode = transaction` is required — this project's tenant isolation relies on
`SET LOCAL` inside one transaction per request, which only works correctly under
transaction pooling (see `docs/superpowers/specs/2026-07-04-tenancy-auth-core-design.md` §4).
Under `session` pooling the GUC would outlive the request; under `statement`
pooling multi-statement transactions break outright.

List both databases, not just `vyaparbook` — PgBouncer rejects any database not
named here with `FATAL: no such database`, and the test suite connects to
`vyaparbook_test`.

In `/etc/pgbouncer/userlist.txt`, add the app role and its **plaintext** password:
```
"vyaparbook_app" "<password>"
```

The plaintext is deliberate, not laziness. This Postgres runs
`password_encryption = scram-sha-256` and stores `vyaparbook_app`'s password as a
SCRAM verifier (`select rolname, rolpassword from pg_authid` to confirm).
PgBouncer authenticates twice — client→proxy, then proxy→Postgres — and the
second hop needs to answer a SCRAM challenge, which is only possible from the
plaintext password or a stored SCRAM secret. An md5 hash in `userlist.txt` is
enough to check an incoming client but cannot produce a SCRAM response, so the
backend connection fails even though the client hop looks fine. Keep the file
`chmod 640`, owned by the `postgres` user.

The alternative that avoids storing the plaintext is `auth_query` — point
PgBouncer at a `SECURITY DEFINER` function that reads `pg_shadow`, so it fetches
each role's verifier from Postgres on demand. Worth doing before this reaches a
real server; the plaintext file is acceptable only for local dev.

Then restart and verify the connection actually goes through the proxy:
```bash
sudo service pgbouncer restart
PGPASSWORD=<password> psql -h 127.0.0.1 -p 6432 -U vyaparbook_app -d vyaparbook_test -c 'select 1;'
```
A `select 1` that returns through 6432 is the real check — `pg_isready` on 6432
succeeds even against the stock config, because PgBouncer answers the port long
before it knows whether the database is routable. That false green is what hid
this gap.

Once that succeeds, set `DB_PORT=6432` in `.env` and re-run `php artisan test`.

## App setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
# Fill in DB_* and DB_MIGRATE_* in .env to match your Postgres/PgBouncer setup.
# DB_PORT must be PgBouncer's port (6432), not Postgres's (5432) — pointing it at
# 5432 bypasses PgBouncer entirely, so nothing in the app or the test suite ever
# exercises transaction pooling and the Task 16 test proves less than it appears to.
# DB_MIGRATE_PORT is the one that goes direct to Postgres (5432).
php artisan migrate --database=pgsql_migrate
php artisan serve
```

In a separate terminal, run the queue worker:
```bash
cd backend
php artisan queue:work
```

On WSL, the native services do not survive a restart and must be started by hand:
```bash
sudo service postgresql start && sudo service pgbouncer start && sudo service redis-server start
```

## Running tests

```bash
cd backend
php artisan test
```

The suite does not use Laravel's `RefreshDatabase` — see `tests/RefreshesTenantDatabase.php`
for why (it is incompatible with the restricted role, RLS, and the one-transaction-per-request
design). It migrates once per run and truncates as the privileged role between tests.

## Notes

- All schema migrations run against the `pgsql_migrate` connection (a privileged
  role, direct to Postgres) — the app's runtime connection (`pgsql`, through
  PgBouncer, as the restricted `vyaparbook_app` role) has no DDL rights by design.
- Every tenant-scoped table is protected by Postgres Row-Level Security *and* an
  app-level scope (defense in depth) — see `app/Traits/BelongsToTenant.php`.
  `memberships` is the deliberate exception to the flat scope: it carries a
  bespoke RLS policy so a user's memberships stay visible before any tenant is
  selected. `businesses`, `users`, `invites`, and `otp_codes` are not tenant-owned
  data and carry no RLS — see the design spec for the reasoning.

## Catalog API

All catalog routes require a selected tenant (`auth:api` + `tenant.context` +
`require.tenant`).

| Route | Roles | Notes |
|---|---|---|
| `GET /api/v1/catalog` | any | Whole catalog in one payload; `?include_archived=1` for the management view |
| `POST /api/v1/catalog/seed` | owner, admin | One-time onboarding; 409 if the catalog is non-empty |
| `POST\|PATCH\|DELETE /api/v1/products/{id}` | owner, admin | `DELETE` archives, it does not delete |
| `POST /api/v1/products/{id}/restore` | owner, admin | |
| …same shape for `/pack-sizes` and `/product-packs` | owner, admin | |

Templates live in `database/catalog_templates/*.php`. Adding a vertical is a new
file — no migration, no code change. `blank` is a valid template that seeds nothing.

Two behaviours that look like bugs and are not:

- **A cross-tenant row returns 404, not 403.** RLS hides other tenants' rows, so
  `findOrFail` genuinely finds nothing. This also avoids confirming that another
  tenant's id is real.
- **`Rule::unique('pack_sizes', 'label')` has no tenant clause.** Validation runs
  inside the request transaction with `app.current_tenant` set, so RLS has already
  narrowed the table to one business.

Archiving is evaluated at read time and never cascaded: a product pack is hidden
when it, its product, or its pack size is archived, but archiving a product does
not write `archived_at` onto its packs. This keeps restore lossless.

## Khata & Sync API

The transactional core — customers, an append-only sales/payments ledger, and the
offline sync endpoints. All routes require a selected tenant (`auth:api` +
`tenant.context` + `require.tenant`).

| Route | Roles | Notes |
|---|---|---|
| `GET /api/v1/khata` | any | Every customer with its outstanding; `?include_archived=1` for the full view |
| `GET /api/v1/khata/{id}` | any | One customer's time-ordered statement with a running balance |
| `POST\|PATCH\|DELETE /api/v1/customers/{id}` | owner, admin, salesman | `DELETE` archives; idempotent create by `uuid` |
| `POST /api/v1/sales` | owner, admin, salesman | Idempotent by `uuid`; freezes each line's rate |
| `POST /api/v1/sales/{id}/void` | owner, admin | Writes a reversing entry; never mutates the original |
| `POST /api/v1/payments` | owner, admin, salesman, accountant | Idempotent by `uuid` |
| `POST /api/v1/payments/{id}/reverse` | owner, admin | Reversing entry |
| `POST /api/v1/sync/push` | any (per-mutation role check) | Drains the offline outbox; see below |
| `GET /api/v1/sync/pull?since=` | any | Delta since a `sync_seq` cursor |

Four rules that look surprising and are deliberate:

- **The ledger is append-only.** A sale or payment is immutable once written. A
  void or reversal is a *new* row whose `reverses_id` points at the original and
  whose amounts are negated. `outstanding = opening_balance + Σ sale.total −
  Σ payment.amount` is therefore always recomputable, and reversals net themselves
  out. Nothing is ever edited or deleted.
- **`sale_lines.rate` is a snapshot.** It is copied from the product pack at sale
  time and stored, never read live — a two-year-old sale reflects the price then,
  not today's catalog.
- **Every write is idempotent by `(business_id, uuid)`.** A sale/payment/customer
  retried over a flaky link posts exactly once; the replay returns the existing
  row (`200`) instead of creating a duplicate (`201`). The REST endpoints and
  `sync/push` share one write path (`LedgerWriter`), so online and offline creates
  cannot drift.
- **A cross-tenant reference returns 404, not 403** (RLS hides the row), and
  `sync/push` rejects any mutation whose `tenant_id` ≠ the session tenant at the
  app layer *before* RLS's `WITH CHECK` would — reported per item, never fatal to
  the batch.

Delta sync uses a globally monotonic `sync_seq` (a Postgres sequence stamped on
every insert/update via `HasSyncSequence`). `pull` returns rows with `sync_seq >
since` ordered by `sync_seq`, plus the new cursor; RLS guarantees the response
holds only the caller's tenant. Archived rows ride the delta so the client learns
to hide them.

## Stock & Production API

Raw-material **stock** (a signed-movement ledger) and **production** (batches that
consume materials and draw stock down). Same tenant-isolated, append-only,
sync-ready foundation as the khata. All routes require a selected tenant
(`auth:api` + `tenant.context` + `require.tenant`).

**Owner/admin only — reads included.** Unlike the catalog and khata (open reads),
*every* stock and production endpoint is gated by `StockPolicy::manage()`.
Salesman and accountant have no access at all, GETs included (PRD §7). This is the
one module that departs from the open-reads rule.

| Route | Roles | Notes |
|---|---|---|
| `GET /api/v1/stock` | owner, admin | Every material with `on_hand`, `reorder_level`, `below_reorder`; `?include_archived=1` for the full view |
| `GET /api/v1/stock/{id}` | owner, admin | One material's movement ledger with a running on-hand |
| `POST\|PATCH\|DELETE /api/v1/raw-materials/{id}` | owner, admin | `DELETE` archives; idempotent create by `uuid` |
| `POST /api/v1/stock-movements` | owner, admin | Record an `in`/`out`/`adjust`; idempotent by `uuid` |
| `POST /api/v1/production` | owner, admin | Create a batch; consumes materials + draws stock down; idempotent by `uuid` |
| `GET /api/v1/production` | owner, admin | Batches newest first |
| `GET /api/v1/production/{id}` | owner, admin | One batch with its material consumptions |
| `GET /api/v1/sync/pull?since=` | owner, admin (stock rows) | The four stock/production tables ride the delta; withheld by role for salesman/accountant |

Rules that look surprising and are deliberate:

- **Stock on hand is `Σ qty`, never a stored number.** `stock_movements.qty` is the
  *signed* effect on stock. The API takes a `kind` and a positive magnitude and
  derives the sign — `in` → `+qty`, `out` → `−qty` — while `adjust` takes a signed
  delta directly. So `Σ qty` is the on-hand total and an `out` can never raise
  stock. On-hand is always recomputable, exact via bcmath at scale 3.
- **The ledger is append-only; correct with an `adjust`.** A movement is immutable.
  A physical recount is a *new* `adjust` movement, not an edit — history and offline
  replay are preserved, exactly as the khata voids with a reversing row.
- **Completing a batch draws stock down through the same ledger.** `POST /production`
  writes, in one transaction, a `MaterialConsumption` per line *and* an `out`
  `StockMovement` (signed negative) tagged with `production_batch_id`. So on-hand
  drops through the very ledger `GET /stock` reads — never a separate number that
  can drift — and each draw-down is traceable back to its batch.
- **Negative stock is allowed.** Over-consumption is recorded and flagged
  (`below_reorder` / a negative `on_hand`), never blocked — "soft-block, never data
  loss" (PRD §8). Hard-blocking would also break offline.
- **Every top-level write is idempotent by `(business_id, uuid)`.** A retried
  material, movement or batch posts exactly once; the replay returns the existing
  row (`200`) instead of a duplicate (`201`), and a batch replay performs no second
  draw-down. `material_consumptions` is a child of its batch (no `uuid`), like
  `sale_lines`.
- **A cross-tenant reference returns 404, not 403** (RLS hides the row).
