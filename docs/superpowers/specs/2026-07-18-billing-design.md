# Billing & Subscription — Design Spec

**Date:** 2026-07-18
**Status:** Approved for planning
**Parent doc:** VyaparBook PRD v2.0 (Multi-Tenant SaaS), §8 (Billing), §7 (RBAC)

## 1. Purpose & scope

Give each tenant a **subscription** — a plan, a trial, and a status — and **enforce plan limits server-side as a soft-block** (an upgrade prompt, never data loss). This is the billing slice of Phase 1, built on the same tenant-isolated foundation as catalog/khata/stock.

v1 is deliberately minimal (PRD §8: "v1 can start minimal … automate fully in Phase 2"): a 14-day full-feature trial, **two tiers (Free + Pro)**, one paid tier reached by **manual/UPI payment**, and live enforcement on the counts and features that matter.

**In scope:**
- `Subscription` (plan, status, trial window, paid period) — one per business, RLS-isolated; provisioned on business creation as a 14-day trial.
- `SubscriptionPayment` — append-only manual/UPI payment ledger, idempotent by `uuid`.
- `PlanCatalog` (code, not a table) — `free`/`pro` tiers with limits + feature flags; trial entitles Pro.
- `EntitlementService` — effective plan, live usage, limit checks, feature checks, write-permission, trial days left.
- `SubscriptionService` — provisioning, trial-expiry transition, and `activateFromPayment()` (the seam Superadmin's verify action calls).
- **Enforcement** — soft-block (`402`) on customer-create and user-invite over the cap, on stock/production without the feature, and a `plan.gate` middleware blocking all domain writes in the `read_only` state.
- `BillingPolicy` — owner only (admin excluded).
- `GET /billing` and `POST /billing/payments` (owner).
- DB-level RLS proof + cross-tenant leak coverage.

**Out of scope (deferred, noted where relevant):**
- **Payment verification / activation endpoint** — flipping a `pending` payment to `verified` and activating the plan is a **platform/superadmin** act (PRD §12); it ships with the Superadmin module. v1 builds and unit-tests `SubscriptionService::activateFromPayment()`; the payment columns for verification exist now so the endpoint drops in cleanly.
- **Razorpay / automated recurring billing, GST invoice PDFs, dunning retry jobs (Celery)** — Phase 2 (PRD §8).
- **Offline sync of billing state** — billing is an online, owner-only concern; the subscription does not ride `sync/pull` in v1.
- **Redis caching of usage counts** — counts are computed live (COUNT under RLS); a tenant-namespaced cache is a later optimisation, as with the khata outstanding cache.
- **Automated trial-expiry / dunning scheduler** — the trial transition is computed on read; a nightly sweep and the automated move to `read_only` are Phase 2. Nothing auto-triggers `read_only` in v1; Superadmin sets it.
- **A third "Business" tier** — PRD §8 lists it illustratively, but multi-outlet (Phase 4) and reports (Phase 2) are its only features and neither exists yet. Encoding it now is speculative; it is added when it gates something. Any frontend.

## 2. Stack decisions for this slice

- **Backend:** Laravel (PHP 8.3), following the existing slices. No payment-gateway SDK in v1 — payments are recorded manually.
- **Plans in code, not data:** `PlanCatalog` is a versioned code map. The tier set is small and fixed and changes with deploys, not per tenant; a `plans` table would be tenant-agnostic reference data with no tenant to own it.
- **`bcmath`** for money (18% GST computation), scale 2 — consistent with the khata ledger.
- **Testing:** Pest, `Carbon::setTestNow()` for trial/period expiry, exact decimal **string** money assertions.

## 3. Data model

Both tables are tenant-owned: RLS policy (`business_id = current_setting('app.current_tenant')`) **and** the `BelongsToTenant` app scope, plus `HasVersion`. Neither carries `sync_seq` — billing does not sync in v1.

```php
// subscriptions — one per business, the source of truth for plan + status
$table->uuid('id')->primary();
$table->foreignUuid('business_id')->unique()->constrained('businesses')->cascadeOnDelete();
$table->string('plan', 20);            // free | pro  (the paid tier once the trial is over)
$table->string('status', 20);          // trialing | past_due | active | read_only | canceled
$table->timestamp('trial_ends_at')->nullable();
$table->timestamp('current_period_end')->nullable(); // set when a paid period is active
$table->unsignedInteger('version')->default(1);
$table->timestamps();
// RLS: subscriptions_isolation

// subscription_payments — append-only manual/UPI ledger
$table->uuid('id')->primary();
$table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
$table->uuid('uuid');                  // client/idempotency key; unique per tenant
$table->string('plan', 20);            // the tier being paid for (pro)
$table->decimal('amount', 12, 2);      // base amount paid
$table->decimal('gst_amount', 12, 2)->default(0); // 18% SaaS GST (PRD §8)
$table->string('mode', 20);            // upi | bank | manual
$table->string('reference', 100)->nullable();     // UPI txn ref etc.
$table->unsignedSmallInteger('period_months');    // months of access purchased
$table->string('status', 20)->default('pending'); // pending | verified | rejected
$table->timestamp('verified_at')->nullable();     // set by Superadmin later
$table->foreignId('verified_by')->nullable()->constrained('users'); // platform user; null in v1
$table->string('note', 255)->nullable();
$table->unsignedInteger('version')->default(1);
$table->timestamps();
$table->unique(['business_id', 'uuid']);
// RLS: subscription_payments_isolation
```

**Why `subscriptions` is not append-only:** unlike the khata/stock ledgers, plan/status/period are in-place updates guarded by `HasVersion`; a subscription is current state, not history. The **payments** are the append-only, auditable record (a wrong payment is `rejected` and a new one recorded, never an edited amount).

`businesses.plan` (the legacy string from the tenancy-core migration) is **superseded** by `subscriptions` and left untouched to avoid a cross-cutting migration mid-slice; a cleanup removes it later.

## 4. Plans in code — `PlanCatalog`

| Plan | max_customers | max_users | features |
|---|---|---|---|
| `free` | 50 | 1 | — |
| `pro` | ∞ (`null`) | 5 | `stock_production` |

- `TRIAL_ENTITLEMENT = 'pro'` — the trial is full-feature.
- `null` limit means **unlimited** (never `0`).
- An unknown plan string **throws** (fail loud, never silently unlimited).
- Methods: `limits(plan)`, `maxCustomers(plan): ?int`, `maxUsers(plan): ?int`, `has(plan, feature): bool`.

## 5. Entitlement resolution — `EntitlementService`

The single resolver for "what can this tenant do right now." No endpoint hand-rolls "is the trial still on."

- **`effectivePlan(sub): string`** —
  - `trialing` and not past `trial_ends_at` → `pro` (trial entitlement)
  - `active` and not past `current_period_end` → the stored `plan` (`pro`)
  - `past_due` | `read_only` | `canceled`, or any expired trial/period → `free` **floor**
- **`mayWrite(sub): bool`** — `false` only for `read_only` (dunning) and `canceled`. `past_due`/expired-trial still writes, within the Free limits — trial-over is a *plan-gate*, not a *write-block*.
- **`usage(sub): array`** — live `{customers, users}` under RLS: `customers` = non-archived customers; `users` = memberships.
- **`isOverLimit(sub, resource): bool`** — usage vs the effective plan's limit; `null` limit → never over. "Over" means the *next* create would exceed (50 ok, the 51st is over).
- **`hasFeature(sub, feature): bool`** — `PlanCatalog::has(effectivePlan(sub), feature)`.
- **`trialDaysLeft(sub): int`** — 0 when not trialing or past the end.

## 6. Status lifecycle (v1)

```
 provisionTrial()            activateFromPayment()  [Superadmin, later]
        │                             │
        ▼                             ▼
   ┌──────────┐  trial/period ends  ┌──────────┐   pay + verify   ┌────────┐
   │ trialing │ ──────────────────▶ │ past_due │ ───────────────▶ │ active │
   └──────────┘   (Pro → Free floor)└──────────┘                  └────────┘
                                        │  dunning (Superadmin / Phase 2)
                                        ▼
                                   ┌───────────┐
                                   │ read_only │  writes soft-blocked, reads OK
                                   └───────────┘
```

- **`provisionTrial(businessId)`** — on business creation: `plan=free`, `status=trialing`, `trial_ends_at=now()+14d`. Idempotent.
- **`syncStatus(sub)`** — computed on read/touch: an expired `trialing` (or an `active` past its `current_period_end`) → `past_due`. Bumps `version` only on change. This is how trial expiry takes effect without a scheduler.
- **`activateFromPayment(payment)`** — built and unit-tested now, **invoked by Superadmin later**: set `plan=payment.plan`, `status=active`, extend `current_period_end` by `period_months` (from the later of now / current end), mark the payment `verified`. Idempotent on an already-`verified` payment (no double extension).
- **`read_only` / `canceled`** — dunning states set by Superadmin (Phase 2 automation). Nothing auto-triggers them in v1, but the gate (§7) is built and tested by forcing the status.

## 7. Enforcement — soft-block, `402`, never data loss

Every refusal is a soft-block: HTTP `402 Payment Required` with `{message, code, resource?, upgrade: true}`, no row created, existing data untouched. An **idempotent replay** of an existing `uuid` is never blocked (it creates nothing).

- **Customer create** — `isOverLimit(sub, 'customers')` → `402` `{code: 'plan_limit', resource: 'customers'}`.
- **User invite** — `isOverLimit(sub, 'users')` → `402` `{code: 'plan_limit', resource: 'users'}` (counts memberships).
- **Stock/production** — `! hasFeature(sub, 'stock_production')` → `402` `{code: 'plan_limit', resource: 'stock_production'}`. On the Free floor (expired trial, unpaid) stock is unavailable; on trial/Pro it works.
- **Read-only gate** — an `EnforceActivePlan` middleware (`plan.gate`) on the domain write group: a mutating request (POST/PATCH/PUT/DELETE) when `status === 'read_only'` → `402` `{code: 'read_only'}`. GET/HEAD pass. **`GET /billing` and `POST /billing/payments` are exempt** — an owner must be able to see and pay their bill to escape the gate.

## 8. RBAC & endpoints — owner only

PRD §7 "Billing & plan" is **owner only** (admin excluded, unlike every prior slice). `BillingPolicy::manage()` → `app('tenant.role') === 'owner'`; every billing endpoint calls it. Under `auth:api` + `tenant.context` + `require.tenant`.

| Route | Role | Notes |
|---|---|---|
| `GET /api/v1/billing` | owner | Effective plan, status, limits vs live usage, trial days left, period end, payments (newest first) |
| `POST /api/v1/billing/payments` | owner | Record a manual/UPI payment (`pending`); idempotent by `uuid`; 18% GST computed server-side; **does not activate** |

Validation for `POST /billing/payments`: `uuid?`, `plan` ∈ {`pro`}, `amount` numeric > 0, `mode` ∈ {`upi`,`bank`,`manual`}, `reference?`, `period_months` int 1..24, `note?`. `gst_amount = round(amount * 0.18, 2)` via bcmath.

## 9. Testing

Pest, existing conventions (no `RefreshDatabase`; `RefreshesTenantDatabase`; `Model::on('pgsql_migrate')` for setup; exact decimal string money). `Carbon::setTestNow()` for trial/period expiry.

- **Unit:** `PlanCatalog` (limits/features/trial/unknown-throws); `EntitlementService` (trial→Pro, expired→Free floor, over-limit at the cap, unlimited never over, feature gate, mayWrite); `SubscriptionService` (idempotent provisioning, trial→past_due, activate extends + verifies, replay no double-extend).
- **Feature:** provisioning on business create; billing read; payment recording (GST, idempotency, no activation); plan enforcement (51st customer / 2nd user / stock feature blocked on Free, allowed on trial); read-only gate; owner-only RBAC (admin 403).
- **Isolation:** DB-level RLS proof (query builder, app layer bypassed) for both tables; cross-tenant leak cases (B's subscription/payments never in A's read; A's read-only never gates B).

Baseline entering this slice is **231** (end of stock/production); target **~275 passing**.

## 10. Design decisions (rationale)

- **Full enforcement in v1** — PRD §8 makes server-side limit checks the *point* of billing; a subscription that gates nothing is bookkeeping. So the enforcement points (customer/user caps, feature gate, read-only) ship now.
- **Verification deferred to Superadmin** — flipping a payment to `verified` is a platform act; building `activateFromPayment()` (tested) now means Superadmin adds only an endpoint, not logic. Owners *record* a payment in v1; a human verifies out-of-band.
- **Two tiers (Free + Pro)** — matches "one paid plan." A Business tier would gate nothing until Phase 2–4 (YAGNI).
- **Trial = top-tier entitlement, `plan` = the paid floor after** — one `status` field plus `effectivePlan()` is the single resolver; trial expiry is a plan-gate to Free, not a write-block (PRD "trial then plan-gate"; read-only is reserved for dunning).
- **Subscription is current state, not history** — in-place updates guarded by `HasVersion`; the **payments** are the append-only audit trail.
- **`businesses.plan` left in place** — superseded but untouched to avoid a cross-cutting migration mid-slice; cleaned up later.

**Known risk unchanged:** PgBouncer is not configured; the suite proves RLS/`SET LOCAL` against Postgres directly, not transaction pooling in situ.
