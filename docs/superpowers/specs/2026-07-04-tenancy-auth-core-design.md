# Tenancy & Auth Core — Design Spec

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

**Date:** 2026-07-04
**Status:** Approved for planning
**Parent doc:** VyaparBook PRD v2.0 (Multi-Tenant SaaS)

## 1. Purpose & scope

This is the first buildable sub-project of VyaparBook. Every other domain module (catalog, sales/khata, stock, production, billing, superadmin) depends on tenant isolation and auth existing and being correct, so it is built and proven first, in isolation.

**In scope:**
- `Business`, `User`, `Membership`, `Invite`, `OtpCode` models and migrations.
- PostgreSQL Row-Level Security policies for tenant isolation, plus an app-level (Eloquent) tenant scope as a second, independent layer.
- Tenant-context propagation per HTTP request (the `SET LOCAL` / PgBouncer correctness problem from PRD §4.2) and per queued job.
- Auth: phone OTP (stubbed delivery) and email/password, both issuing JWTs with `sub`/`tid`/`role` claims.
- Business creation, "my businesses" listing, business switch (re-issue token), staff invite + accept.
- API versioned under `/api/v1/...`.
- A cross-tenant-leak test suite (Pest) that actively tries to leak data between tenants and must fail to do so.

**Out of scope (deferred to later slices):**
- Catalog, Sales/Khata, Stock, Production domains.
- Billing/subscriptions, plan enforcement.
- Superadmin console (the `is_platform_admin` flag is added now but unused).
- Real SMS/OTP provider integration (MSG91/Twilio/Firebase) — stubbed for this slice.
- Production web server (nginx/Caddy) and deployment topology — dev runs via `php artisan serve`.
- Offline sync (`/api/sync`), WhatsApp reminders, GST/e-invoicing.

## 2. Stack decisions for this slice

- **Backend:** Laravel (PHP 8.3), per explicit user direction — supersedes the PRD's Django pseudocode in §10, which is treated as illustrative only.
- **Auth/JWT:** `php-open-source-saver/jwt-auth` (maintained fork of tymon/jwt-auth) — chosen over Sanctum (can't naturally carry custom `tid`/`role` claims in the token) and Passport (unneeded OAuth2 complexity).
- **Testing:** Pest.
- **No Docker.** Postgres, PgBouncer, and Redis run as native system services; the app runs via `php artisan serve` in dev. Containerized/production deployment is a future slice's concern.
- **Multi-business support:** a user may hold `Membership`s in more than one `Business` (per PRD §3), with a switcher endpoint that re-issues a JWT for the selected `tid`.

## 3. Data model

```php
// businesses
$table->uuid('id')->primary();
$table->string('name', 120);
$table->string('city', 80)->nullable();
$table->string('gstin', 15)->nullable();
$table->string('default_language', 8)->default('hi');
$table->string('plan', 20)->default('trial');
$table->timestamps();

// users (extends Laravel's default users table)
$table->string('phone', 15)->unique()->nullable();
$table->boolean('is_platform_admin')->default(false);
// app-level check: at least one of phone/email must be set

// memberships
$table->uuid('id')->primary();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
$table->enum('role', ['owner','admin','salesman','accountant']);
$table->timestamps();
$table->unique(['user_id', 'business_id']);

// invites
$table->uuid('id')->primary();
$table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
$table->enum('role', ['owner','admin','salesman','accountant'])->default('salesman');
$table->string('token', 64)->unique();
$table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('expires_at');
$table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('redeemed_at')->nullable();
$table->timestamps();

// otp_codes
$table->id();
$table->string('phone', 15);
$table->string('code_hash', 128);   // sha256, never store plaintext
$table->timestamp('expires_at');
$table->unsignedTinyInteger('attempts')->default(0);
$table->timestamp('consumed_at')->nullable();
$table->timestamps();
```

`Business` and `User` are global (no RLS). `Invite` and `OtpCode` are pre-membership and not tenant-scoped by RLS either, but are scoped by their own natural keys (`token`, `phone`) with expiry/attempt limits. `Membership` is tenant-scoped but must be queryable before a tenant is selected (to resolve which businesses a user belongs to), so it gets a dedicated RLS policy rather than the flat policy every future domain table will use:

```sql
CREATE POLICY membership_isolation ON memberships
  USING (
    user_id = current_setting('app.current_user_id', true)::bigint
    OR business_id = current_setting('app.current_tenant', true)::uuid
  );
```

(`true` as the second arg to `current_setting` returns null instead of erroring when unset — needed for pre-auth queries.)

## 4. Tenant context propagation

Laravel has no `ATOMIC_REQUESTS`-equivalent, so a `TenantContext` middleware (registered near the top of the `api` middleware group) does the following for every authenticated route:

1. Decode the JWT (via the `jwt-auth` guard) to get `sub` and `tid`. Skipped entirely for unauthenticated routes (signup, OTP, login).
2. Verify an active `Membership` exists for `(sub, tid)` — return 403 if not (covers a token that's stale because the user was removed from that business).
3. `DB::beginTransaction()`, then `DB::statement("SET LOCAL app.current_tenant = ?", [$tid])` and `DB::statement("SET LOCAL app.current_user_id = ?", [$sub])`, then `$next($request)`.
4. Commit on a normal response; roll back and re-throw on exception, so Laravel's exception handler still reports correctly.

This keeps the whole request inside one Postgres transaction/connection checkout, which is required for `SET LOCAL` to survive under PgBouncer transaction-pooling mode (PRD §4.2) — the direct Laravel equivalent of Django's `ATOMIC_REQUESTS` + middleware pattern the PRD describes.

**App-level defense in depth:** every tenant-owned Eloquent model (starting with `Membership`, and every future domain model) uses a `BelongsToTenant` trait providing a global scope (`where('business_id', app('tenant.id'))`) and a `creating` event hook that stamps `business_id` automatically. This is a second, independent enforcement layer — RLS is the backstop if this layer has a bug, and vice versa.

**Queued jobs:** a `TenantAwareJob` trait captures `tenant_id` at dispatch time and wraps `handle()` in `DB::transaction()` + the same `SET LOCAL` calls before delegating to the job's actual logic. No job runs without an explicit tenant in scope.

## 5. Auth flows

**Phone OTP (delivery stubbed):**
- `POST /api/v1/auth/otp/request` — `{phone}`. Rate-limited (3/hour/phone). Generates a 6-digit code, stores its hash + 5-minute expiry. Logs the code; returns it in the response body **only when `APP_ENV` is `local` or `testing`** — never in staging/production responses.
- `POST /api/v1/auth/otp/verify` — `{phone, code}`. Checks hash, expiry, `attempts < 5`; finds-or-creates the `User` by phone; issues a JWT. If the user has exactly one `Membership`, `tid`/`role` are set on the token immediately; otherwise both are omitted and the client must call `/businesses/mine` then `/businesses/{id}/switch`.

**Email/password:**
- `POST /api/v1/auth/register` — `{name, email, password}`.
- `POST /api/v1/auth/login` — `{email, password}` → same token-issuing logic as OTP verify.

**JWT claims:** `sub`, `tid` (nullable), `role` (nullable), `iat`/`exp`. Access token ~15 min, refresh token ~7 days via `jwt-auth`'s refresh flow.

## 6. Business & membership endpoints

- `POST /api/v1/businesses` — `{name, city, gstin?, default_language?}`. Auth required, no `tid` needed yet. Creates `Business` + an `owner` `Membership` for the caller; returns a **new JWT** with `tid` already set to the new business.
- `GET /api/v1/businesses/mine` — `[{business, role}]` for the caller's memberships.
- `POST /api/v1/businesses/{id}/switch` — requires an existing `Membership` for `(user, id)`; re-issues a JWT with `tid=id`, `role=<that membership's role>`.
- `POST /api/v1/businesses/{id}/invite` — owner/admin only (Laravel Policy, checked against `app('tenant.role')` resolved by the middleware). `{role}` (default `salesman`). Creates an `Invite` (random token, 7-day expiry); returns the invite link — no delivery channel yet, same stub philosophy as OTP.
- `POST /api/v1/invites/accept` — `{token}`, auth required. Validates not expired/redeemed, creates the `Membership`, marks the invite redeemed, returns a JWT with the new `tid`.

## 7. Testing

- Pest feature tests per endpoint: happy path, validation failures, OTP rate-limit/expiry/attempt-limit edge cases.
- **Cross-tenant-leak suite:** for every tenant-scoped table, create two businesses with data, authenticate as a user of Business A, and assert via the HTTP API (not the ORM directly) that Business B's rows are never visible, never editable, and that switching to a business the user has no membership in is rejected. Written to actively try to leak; must fail to do so.
- A dedicated test simulates the PgBouncer pooled-connection scenario: two sequential requests for different tenants reusing the same pooled connection must not see each other's `SET LOCAL` value.

## 8. Error handling

- JWT/auth failures → 401, generic message (no user-enumeration hints).
- Valid token but no matching `Membership` for `tid` → 403.
- Any RLS policy violation that reaches the DB layer despite the app-level scope (i.e., defense-in-depth's first layer failed) → caught, logged at `error` level with tenant/user context for alerting, returned as a generic 500 — the response body never includes another tenant's data.

## 9. Environment & infra

- Native services: PostgreSQL, PgBouncer (transaction-pooling mode), Redis — installed directly, not containerized.
- App DB connection (`.env`) points at PgBouncer's port, not directly at Postgres.
- Dev: `php artisan serve` + `php artisan queue:work` as a separate local process.
- README documents exact install/config steps: Postgres non-superuser app role (per PRD §4.1 — RLS is bypassed by superusers, so migrations run as a privileged role while the app connects as a restricted one), PgBouncer config, `.env` setup.
- Production web server and deployment topology are explicitly deferred to a later slice.
