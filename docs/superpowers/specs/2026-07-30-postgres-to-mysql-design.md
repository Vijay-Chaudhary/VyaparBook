# PostgreSQL → MySQL Migration — Design

**Date:** 2026-07-30
**Status:** Design approved; step 1 (database config) committed on
`feat/mysql-migration`. Steps 2–6 not started.
**Scope:** Move the application from PostgreSQL to MySQL 8, replacing
engine-enforced tenant isolation with an app-enforced substitute.

---

## Background

The decision to move to MySQL was taken by the project owner after the
consequences below were laid out. This document records what is being traded
away, so that nobody later reads the codebase and believes a protection is in
place that is not.

**PostgreSQL is not incidental here. It is the tenancy model.**

| Postgres dependency | Count |
|---|---|
| Tables with `ENABLE ROW LEVEL SECURITY` | 23 |
| `CREATE POLICY` statements | 23 |
| `current_setting('app.current_tenant')` references | 47 |
| `set_config(...)` transaction-scoped GUC | the whole tenant mechanism |
| `nextval()` on `sync_seq_global` | 6 |
| `::uuid` / `::text` casts | 46 / 39 |
| `selectRaw` blocks | 35 |
| Files naming `pgsql` / `pgsql_migrate` | 165 (164 after step 1 cleared `config/`) |

**MySQL has no row-level security.** Not different syntax — an absent feature.
`CLAUDE.md` currently states the rule as *"every tenant-owned table enforces RLS
**AND** an app-level tenant scope (defense in depth) — never rely on one layer
alone."* This migration deletes one of those two layers permanently. That
sentence must be rewritten, not left standing.

## Decisions

1. **Rewrite the migrations in place, rather than squashing to a baseline or
   building a dual-engine layer.** Most of the work is *deletion* — the 23 RLS
   blocks are removed, not translated — and rewriting in place preserves the
   migrations' comments, which in this codebase carry the reasoning. A
   dual-engine abstraction would cost more code than the migration itself and
   would make "passes on both engines" a lie about the one thing that differs.

2. **Tenant isolation becomes a fail-closed application scope.**
   `BelongsToTenant` today fails **open**: no tenant context means no predicate,
   so the query returns every tenant's rows. That is safe now only because
   Postgres refuses underneath it. It will throw `TenantContextMissing` instead.

   Four paths legitimately run without a tenant and get an explicit, greppable
   `Tenancy::withoutTenant(fn () => ...)`: seeders, the platform console
   (cross-tenant by design), auth before tenant selection
   (`TenantContext::forUser`), and the inbound WhatsApp STOP write (the app's
   one deliberate cross-tenant mutation). The security question becomes "are
   these four call sites correct?", which a person can audit.

3. **A query tripwire covers what a global scope cannot see.** A scope binds
   Eloquent only; `DB::table('sales')` walks past it, and the report services
   use raw builders. A listener registered **in the test environment** fails any
   statement touching one of the 23 tenant tables without a `business_id`
   predicate outside a `withoutTenant` block. A CI tripwire, not a runtime
   guard.

4. **UUID keys become `CHAR(36)`.** What `foreignUuid()` already emits on MySQL,
   so the migrations need no per-column rework and `HasUuids` is untouched.
   Readable in a shell, which matters when debugging a khata. `BINARY(16)` was
   rejected: it would make the 35 `selectRaw` blocks and every debug query
   unreadable to buy headroom this data size does not need.

5. **The two-role app/migrate split collapses to a single application
   connection.** `pgsql` (restricted app role) and `pgsql_migrate` (table owner)
   existed *only* so the app role could not bypass RLS. With no RLS the split
   protects nothing, and its removal also ends `migrate:fresh` needing
   `--database=pgsql_migrate`.

   This is about the *application* connection. `mysql_platform` (Decision 6)
   remains as a second connection, but it is a different thing — a read-only
   login for the superadmin console, not a second role for the same app.

6. **`pgsql_platform` becomes `mysql_platform`, keeping the half of its
   guarantee that survives.** It logged in as a SELECT-only **BYPASSRLS** role.
   The bypass half is meaningless without RLS; the read-only half is not, and
   MySQL supports it: `GRANT SELECT ON vyaparbook.* TO 'vyapar_platform_ro'@'%'`.
   The console then physically cannot mutate tenant data however wrong the app
   code gets.

7. **`sync_seq` becomes a per-tenant counter, not a global one.** See below.

8. **No data migration.** There is no deployment. The only real data is Shree
   Raj Shyama Ji Namkeen, seeded from spreadsheet exports in
   `database/seed_data/`, which rebuilds with one command. This removes an
   entire ETL workstream and makes rollback `git checkout master`.

## Schema translation

| Postgres | MySQL |
|---|---|
| `uuid` columns | `CHAR(36)` — already what `foreignUuid()` emits |
| `x::text` (39) | `CAST(x AS CHAR)` |
| `extract(month from d)::int` (14) | `EXTRACT(MONTH FROM d)` — the cast drops |
| `jsonb` (1) | `JSON` |
| `FOR UPDATE` (2) | unchanged — InnoDB supports it, so gapless invoice numbering survives |
| `CREATE SEQUENCE sync_seq_global` | per-tenant counter table (below) |
| 23 × RLS policy blocks | deleted |

`strict => true` stays on: it makes MySQL reject bad data rather than silently
truncating, which is the behaviour Postgres gave for free.

### The decimal risk, which is the largest in this migration

Every rupee is a **decimal string** run through bcmath, precisely so money never
touches a float. There are 41 decimal columns and roughly 2,700 assertions
resting on that.

Postgres via PDO returns `DECIMAL` as a string. MySQL does too — **but only with
native prepares**. Enable `PDO::ATTR_EMULATE_PREPARES` and decimals arrive as
PHP floats. Nothing throws. `bcadd()` receives a float, and khatas drift by
paise over months.

So the connection pins `PDO::ATTR_EMULATE_PREPARES => false`, and a test reads a
known decimal back and asserts `is_string()`. That one small test guards the
entire money model.

**Known trap:** Laravel wraps the `options` array in
`extension_loaded('pdo_mysql') ? ... : []`, so on a machine without the
extension the pin silently resolves away. Harmless when no MySQL connection is
possible at all, but it means the setting cannot be verified until
`php8.3-mysql` is installed.

### `sync_seq_global` — a real regression, mitigated

`HasSyncSequence` draws `nextval('sync_seq_global')` on **every insert and every
update** of every synced model. Postgres sequences are lock-free; MySQL has none.

The direct emulation is a counter row incremented with
`UPDATE ... SET v = LAST_INSERT_ID(v+1)` then `SELECT LAST_INSERT_ID()` — atomic
and non-growing. But a *single global* counter would serialise every write on
the platform against one row lock.

`sync_seq` only needs to be monotonic **within a tenant**: the delta pull is
always `business_id = X AND sync_seq > cursor`, and each device holds a
per-tenant Dexie database and a per-tenant cursor. A global sequence is stronger
than the invariant requires.

So: **one counter row per tenant**, in a `sync_sequences` table keyed on
`business_id`. Contention drops from platform-wide to one shop's own writes. The
one-off `restream_open_orders` migration must be rewritten to walk tenants. Safe
only because no deployed device holds a cursor under the old scheme.

Still a regression, stated plainly: a row lock per write where Postgres had
none. Invisible at one shop's volume.

## Architecture

| Unit | Responsibility |
|---|---|
| `config/database.php` | One `mysql` connection plus read-only `mysql_platform`. **Done.** |
| `App\Traits\BelongsToTenant` | Fail-closed scope; throws `TenantContextMissing` with no tenant. |
| `App\Support\Tenancy` | `withoutTenant()` — the only sanctioned escape, used at exactly four sites. |
| `App\Support\TenantContext` | Loses `set_config`; tenant identity lives only in `app('tenant.id')`. |
| `App\Traits\HasSyncSequence` | Draws from the per-tenant counter. |
| Test service provider | The query tripwire. |
| 53 migrations | Single connection, RLS blocks removed, sequence replaced. |

## Testing

**19 tests cannot be ported, and that is the honest measure of this migration.**
Of the 40 in `tests/Feature/Tenancy/`, six files exist to prove the *database*
enforces isolation:

| File | Tests | Fate |
|---|---|---|
| `BillingRlsTest` | 4 | deleted |
| `CatalogRlsTest` | 4 | deleted |
| `KhataRlsTest` | 4 | deleted |
| `MembershipRlsTest` | 2 | deleted |
| `StockRlsTest` | 4 | deleted |
| `PgBouncerPooledConnectionTest` | 1 | deleted with PgBouncer |

`StockRlsTest`'s own header says why they cannot be retargeted: *"Proves the
stock & production RLS policies themselves, with the app layer bypassed — the
global scope cannot mask whether RLS is doing the work."* One test is literally
*"hides another business stock rows even with the app layer bypassed."* On MySQL
there is nothing behind the app layer to do the hiding. These get deleted in one
commit whose message records what was given up — not quietly weakened into
something that still passes and no longer means anything.

**`CrossTenantLeakTest`'s ~20 tests survive.** They drive the HTTP API with both
layers live, so they assert observable behaviour, not mechanism. Two carry
comments like *"404, not 403: RLS hides B's customer"* — the mechanism becomes
the global scope, the 404 is unchanged, and the comments must be corrected or
the codebase lies about itself.

**New:**
- Fail-closed scope tests: querying a tenant-owned model with no tenant context
  throws; each of the four `withoutTenant()` sites is exercised.
- The query tripwire, proven to catch a raw `DB::table()` leak.
- The decimal-is-a-string test.

Net: 895 tests today → roughly 876 + ~12 new. The count barely moves; the
guarantee behind it does.

## Comment and doc reconciliation

**43 files in `app/` mention RLS in comments.** This is a workstream, not a
tidy-up, and it is the one most likely to be skipped because the tests stay
green either way.

1. **Stale and actively misleading — must be fixed.** e.g. `LedgerWriter`: *"RLS
   makes a cross-tenant pack invisible to `whereIn()`"*; `OrderWriter`:
   *"findOrFail under RLS: another tenant's customer is invisible → 404"*. After
   this migration those sentences are false. The 404 still happens, but because
   the scope adds a predicate, not because the database refuses. **A wrong
   comment about security is worse than no comment.**
2. **Historical record — keep.** Dated specs under `docs/superpowers/specs/` say
   what was true when written. Supersede with a pointer, as F-16 did to F-12.
3. **Live documentation — correct.** `CLAUDE.md`'s tech-stack line and its
   defense-in-depth rule; PRD §4.1–4.3; the two memory notes about PgBouncer and
   `--database=pgsql_migrate`, both of which become wrong on merge.

**Acceptance gate:** `grep -r "pgsql" app/ database/ config/ tests/ routes/`
returns zero matches. If it does not, the migration is not finished.

## Cutover

1. Schema + connection collapse → `migrate:fresh --seed` succeeds on MySQL.
2. Fail-closed scope + `withoutTenant` at the four sites.
3. Raw SQL translation → report services green.
4. Tripwire on; fix what it catches.
5. Delete the six obsolete test files in one commit, reasoning in the message.
6. Comment and doc reconciliation; run the grep gate.
7. Full suite green, `npm run build` clean, manual pass over khata, `/pricing`
   and the sync pull against reseeded data.

**Rollback is `git checkout master`.** No data has moved and Postgres is
untouched on disk.

## Environment prerequisites

Both need sudo and neither is satisfied on the current machine:

- **`php8.3-mysql` is not installed.** `PDO::getAvailableDrivers()` returns
  `['pgsql']` only, so the app cannot open a MySQL connection at all. `apt` here
  offers `php8.1-mysql`; the 8.3 build comes from the sury repo.
- **MySQL server is not running.**

```
sudo apt install php8.3-mysql && sudo service mysql start
```

## What is being given up

Stated once, plainly, so it is on the record:

- **Engine-enforced tenant isolation.** One missing scope, one raw
  `DB::statement`, and a shop reads another shop's khata with nothing behind the
  application to stop it. Today Postgres refuses below the app.
- **19 tests that proved the database itself was safe.**
- **Lock-free sequence allocation**, replaced by a per-tenant row lock.

Retained: the read-only platform user, `FOR UPDATE` invoice counters, strict
mode, and every app-layer check that exists today.

## Out of scope

Data migration (no deployment exists); database-per-tenant (considered and
rejected as a re-architecture, not a migration — it would break the platform
console's cross-tenant reporting and the WhatsApp STOP write); MariaDB; any
change to business logic, offline sync semantics or the React layer.

## Traceability

- `CLAUDE.md` "PostgreSQL (RLS for tenant isolation)" → superseded by Decision 2.
- `CLAUDE.md` "never rely on one layer alone" → **no longer achievable**; must be
  rewritten to describe the fail-closed scope and the tripwire.
- PRD §4.1 RLS implementation → superseded.
- PRD §4.2 PgBouncer gotcha → obsolete; PgBouncer leaves the stack.
- PRD §4.3 tenant context propagation → GUC replaced by container binding.
