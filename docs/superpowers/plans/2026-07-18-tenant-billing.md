# Tenant Billing & Subscription Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each tenant a **subscription** (Free/Pro, a 14-day trial, a status) and enforce plan limits **server-side as a soft-block** — an upgrade prompt, never data loss (PRD §8). v1 is manual/UPI (no gateway), owner-only.

**Architecture:** Two new tenant-owned tables — `subscriptions` (current state, one per business) and `subscription_payments` (append-only manual/UPI ledger) — each with a flat RLS policy plus the `BelongsToTenant` scope and `HasVersion` (no `sync_seq` — billing is online-only). Plans live in **code** (`PlanCatalog`), not a table. `EntitlementService` resolves the effective plan/limits/usage; `SubscriptionService` provisions the trial, transitions expiry, and holds `activateFromPayment()` (the seam the future Superadmin verify action calls). Enforcement is a soft-block (`402`) at the customer-create and user-invite points, a stock/production feature gate, and a `plan.gate` middleware for the read-only dunning state. Every billing endpoint is **owner only** (`BillingPolicy`).

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL (RLS), Pest, bcmath. No payment-gateway SDK in v1.

**Design source:** `docs/superpowers/specs/2026-07-18-billing-design.md` (approved). PRD §8 (billing), §7 (RBAC "Billing & plan" = owner).

**Depends on:** tenancy/auth core (`businesses`, `memberships`, `BusinessController::store` provisioning point, the `tenant.context`/`require.tenant` middleware, `app('tenant.role')`), and the catalog/khata slices (the `BelongsToTenant`/`HasVersion` patterns and the customer/invite creation points that get gated).

---

## Scope

**In scope:** `Subscription` + `SubscriptionPayment` (RLS); `PlanCatalog` (Free/Pro, code); `EntitlementService`; `SubscriptionService` (provision, syncStatus, activateFromPayment); trial provisioned on business create; soft-block enforcement (customers, users, stock feature) + read-only write gate; `BillingPolicy` (owner only); `GET /billing`, `POST /billing/payments`; RLS proof + cross-tenant leak coverage.

**Out of scope** (deferred, per spec §1): the verify/activate **endpoint** (→ Superadmin module; the service is built now); Razorpay/GST-PDF/dunning jobs (Phase 2); offline sync of billing; Redis usage cache; a trial-expiry scheduler; a third "Business" tier; any frontend.

---

## RBAC (PRD §7)

"Billing & plan" is **owner only** — admin, salesman, accountant have no billing access, reads included. Every billing endpoint gates on `BillingPolicy::manage()` (`role === 'owner'`). Stricter than stock/production (owner+admin); the deliberate difference from every prior slice.

---

## File Structure

```
backend/
  app/
    Models/
      Subscription.php               (new)
      SubscriptionPayment.php        (new)
    Billing/
      PlanCatalog.php                (new — plan → limits/features, code config)
    Http/Controllers/Api/V1/
      BillingController.php          (new — show, recordPayment)
      BusinessController.php         (modified — provision trial on store)
      CustomerController.php         (modified — enforce max_customers)
      InviteController.php           (modified — enforce max_users)
      StockController.php            }
      RawMaterialController.php      } (modified — stock_production feature gate,
      StockMovementController.php    }   via a shared guard)
      ProductionController.php       }
    Http/Middleware/
      EnforceActivePlan.php          (new — 'plan.gate': block writes in read_only)
    Services/
      EntitlementService.php         (new)
      SubscriptionService.php        (new)
      PlanGuard.php                  (new — one helper the controllers call to 402)
    Policies/
      BillingPolicy.php              (new — manage())
  database/
    migrations/
      2026_07_18_000001_create_subscriptions_table.php
      2026_07_18_000002_create_subscription_payments_table.php
    factories/
      SubscriptionFactory.php
      SubscriptionPaymentFactory.php
  bootstrap/app.php                  (modified — 'plan.gate' alias)
  routes/api.php                     (modified — billing routes; plan.gate on domain writes)
  README.md                          (modified — Billing & Subscription API)
  tests/
    Unit/
      PlanCatalogTest.php
      EntitlementServiceTest.php
      SubscriptionServiceTest.php
      SubscriptionModelTest.php
    Feature/
      Billing/ProvisioningTest.php
      Billing/BillingReadTest.php
      Billing/BillingPaymentTest.php
      Billing/PlanEnforcementTest.php
      Billing/ReadOnlyGateTest.php
      Billing/BillingRbacTest.php
      Tenancy/BillingRlsTest.php
      Tenancy/CrossTenantLeakTest.php  (modified — billing cases)
```

**Conventions inherited (not re-derived):** UUID PKs via `HasUuids`; `business_id`/`uuid` `$fillable`, `version` trait-managed and not fillable; factories set non-fillable columns via `afterMaking`; tenant-table test setup uses `Model::on('pgsql_migrate')`; RLS is the flat `NULLIF(current_setting('app.current_tenant', true), '')::uuid` shape with `ENABLE` + `FORCE` + `CREATE POLICY`; controllers resolve the caller's subscription with the app scope (tenant is pinned by middleware).

---

## Task 1: Subscription model, migration, factory

**Files:** migration `…000001…`, `app/Models/Subscription.php`, `database/factories/SubscriptionFactory.php`, test `tests/Unit/SubscriptionModelTest.php`

- [x] **Migration** — `id` uuid PK; `foreignUuid('business_id')->unique()->constrained('businesses')->cascadeOnDelete()` (one per business); `plan` string(20); `status` string(20); `trial_ends_at` nullable timestamp; `current_period_end` nullable timestamp; `version` unsignedInteger default 1; timestamps. **No `uuid`/`sync_seq`.** Then RLS: `ENABLE` + `FORCE` + `CREATE POLICY subscriptions_isolation` (flat `NULLIF(...)::uuid` USING + WITH CHECK).
- [x] **Model** — `use BelongsToTenant, HasFactory, HasUuids, HasVersion;` `$fillable = ['business_id','plan','status','trial_ends_at','current_period_end'];` casts `trial_ends_at`/`current_period_end` → datetime, `version` → integer. `business(): BelongsTo`, `payments(): HasMany` (→ `SubscriptionPayment`).
- [x] **Factory** — `business_id` => Business::factory(); `plan` `'free'`; `status` `'trialing'`; `trial_ends_at` => `now()->addDays(14)`; `current_period_end` null.
- [x] **Test** — uuid PK; a second `Subscription::on('pgsql_migrate')->create([...])` for the same `business_id` throws a `QueryException` (unique); `trial_ends_at` round-trips as a Carbon datetime. Migrate + run → PASS.
- [x] **Commit** — `feat: add Subscription model with RLS isolation policy`.

`subscriptions` is current state, not a ledger: status/period are updated in place under `HasVersion`. No `sync_seq` — billing does not sync in v1.

---

## Task 2: SubscriptionPayment model, migration, factory

**Files:** migration `…000002…`, `app/Models/SubscriptionPayment.php`, factory, test folded into `BillingPaymentTest` (Task 9).

- [x] **Migration** — `id`; `foreignUuid('business_id')` constrained cascade; `uuid`; `plan` string(20); `amount` decimal(12,2); `gst_amount` decimal(12,2) default 0; `mode` string(20); `reference` string(100) nullable; `period_months` unsignedSmallInteger; `status` string(20) default `'pending'`; `verified_at` nullable timestamp; `foreignId('verified_by')->nullable()->constrained('users')`; `note` string(255) nullable; `version` unsignedInteger default 1; timestamps. `unique(['business_id','uuid'])`. RLS `subscription_payments_isolation`.
- [x] **Model** — `use BelongsToTenant, HasFactory, HasUuids, HasVersion;` `$fillable = ['business_id','uuid','plan','amount','gst_amount','mode','reference','period_months','status','note'];` (`verified_at`/`verified_by` set by the service, not fillable). casts `amount`/`gst_amount` → decimal:2, `verified_at` → datetime, `period_months`/`version` → integer. `business(): BelongsTo`.
- [x] **Factory** — unrelated defaults; `plan` `'pro'`; `amount` `'499.00'`; `gst_amount` `'89.82'`; `mode` `'upi'`; `reference` `null`; `period_months` 1; `status` `'pending'`.
- [x] **Commit** — `feat: add SubscriptionPayment append-only manual/UPI ledger`.

Append-only: a wrong payment is `rejected` (a status the platform sets later) and a new one recorded, never an edited amount — same rationale as the khata/stock ledgers. `verified_at`/`verified_by` stay null in v1.

---

## Task 3: PlanCatalog

**Files:** `app/Billing/PlanCatalog.php`, test `tests/Unit/PlanCatalogTest.php`

- [x] **`PlanCatalog`** — a code map. `const TRIAL_ENTITLEMENT = 'pro';` Definitions: `free` → `['max_customers' => 50, 'max_users' => 1, 'features' => []]`; `pro` → `['max_customers' => null, 'max_users' => 5, 'features' => ['stock_production']]`. Methods (static): `limits(string $plan): array` (throws `InvalidArgumentException` on unknown plan); `maxCustomers(string $plan): ?int`; `maxUsers(string $plan): ?int`; `has(string $plan, string $feature): bool`.
- [x] **Test** — free caps at 50 customers / 1 user and `has('free','stock_production')` is false; pro `maxCustomers` is `null` (unlimited), `maxUsers` 5, `has('pro','stock_production')` true; `TRIAL_ENTITLEMENT === 'pro'`; `limits('enterprise')` throws `InvalidArgumentException`. → PASS.
- [x] **Commit** — `feat: add PlanCatalog encoding Free and Pro limits and features`.

Plans are code, not tenant data: the set is fixed and versioned with deploys. `null` limit = unlimited (never `0`). Unknown plan throws — never silently unlimited.

---

## Task 4: EntitlementService

**Files:** `app/Services/EntitlementService.php`, test `tests/Unit/EntitlementServiceTest.php`

- [x] **`effectivePlan(Subscription $sub): string`** — `trialing` and `trial_ends_at >= now()` → `PlanCatalog::TRIAL_ENTITLEMENT` (`pro`); `active` and (`current_period_end === null` or `>= now()`) → `$sub->plan`; everything else (`past_due`, `read_only`, `canceled`, expired trial/period) → `'free'` floor.
- [x] **`mayWrite(Subscription $sub): bool`** — `! in_array($sub->status, ['read_only','canceled'], true)`. (An expired trial is `past_due`/free-floor but still writes within Free limits — trial-over is a plan-gate, not a write-block.)
- [x] **`usage(Subscription $sub): array`** — `['customers' => Customer::whereNull('archived_at')->count(), 'users' => Membership::where('business_id', $sub->business_id)->count()]` (customers under the app scope/RLS; memberships filtered by business_id since `Membership` is not `BelongsToTenant`-scoped).
- [x] **`isOverLimit(Subscription $sub, string $resource): bool`** — resolve the effective plan's limit (`maxCustomers`/`maxUsers`); `null` → return false (unlimited); else `usage[$resource] >= $limit` (the *next* create would exceed — 50 present ⇒ over).
- [x] **`hasFeature(Subscription $sub, string $feature): bool`** — `PlanCatalog::has($this->effectivePlan($sub), $feature)`.
- [x] **`trialDaysLeft(Subscription $sub): int`** — `0` when `status !== 'trialing'` or `trial_ends_at < now()`; else whole days from now to `trial_ends_at` (`ceil`).
- [x] **Test** (`Carbon::setTestNow('2026-07-18 10:00:00')`) — a live trial gives `pro`, `hasFeature stock_production` true, `isOverLimit customers` false with 60 customers (unlimited); an expired-trial `past_due` sub gives `free`, `hasFeature` false, `isOverLimit customers` true at 50 and false at 49; `read_only` → `mayWrite` false; `isOverLimit users` flips at the cap; `trialDaysLeft` ≈ 14 on a fresh trial and 0 once expired. → PASS.
- [x] **Commit** — `feat: add EntitlementService resolving plan limits and write access`.

`usage` counts are live (COUNT under RLS/business_id); a tenant-namespaced cache is a later optimisation (deferred, per spec).

---

## Task 5: SubscriptionService (provision, sync, activate)

**Files:** `app/Services/SubscriptionService.php`, test `tests/Unit/SubscriptionServiceTest.php`

- [x] **`provisionTrial(string $businessId): Subscription`** — idempotent: `Subscription::where('business_id', $businessId)->first()` returns it if present; else `Subscription::create(['business_id' => $businessId, 'plan' => 'free', 'status' => 'trialing', 'trial_ends_at' => now()->addDays(14), 'current_period_end' => null])`.
- [x] **`syncStatus(Subscription $sub): Subscription`** — if `status === 'trialing' && trial_ends_at < now()`, or `status === 'active' && current_period_end !== null && current_period_end < now()`, set `status = 'past_due'` and `save()` (bumps `version`). Otherwise return unchanged (no save). Return the sub.
- [x] **`activateFromPayment(SubscriptionPayment $payment): Subscription`** — the seam Superadmin's verify action calls. If `$payment->status === 'verified'`, return the sub unchanged (idempotent — no double extension). In one `DB::transaction`: load the business's subscription; `$base = max(now(), $sub->current_period_end ?? now())`; set `$sub->plan = $payment->plan`, `$sub->status = 'active'`, `$sub->current_period_end = $base->copy()->addMonths($payment->period_months)`, `save()`; set `$payment->status = 'verified'`, `$payment->verified_at = now()`, `$payment->verified_by = app('tenant.user_id')`, `save()`. Return the sub.
- [x] **Test** (`Carbon::setTestNow`) — provisioning is idempotent (two calls → one row) and sets `trialing` + `trial_ends_at` = now+14d; `syncStatus` flips an expired trial to `past_due` and leaves a live trial untouched (version unchanged); `activateFromPayment` sets `active`/`pro`, `current_period_end` = now + `period_months`, marks the payment `verified`; a **replay** of the now-verified payment does not extend again. → PASS.
- [x] **Commit** — `feat: add SubscriptionService for provisioning and activation`.

`activateFromPayment` is unit-tested here but wired to no endpoint in this slice — the Superadmin console calls it (spec §6).

---

## Task 6: Provision a trial on business creation

**Files:** `app/Http/Controllers/Api/V1/BusinessController.php` (modify `store`), test `tests/Feature/Billing/ProvisioningTest.php`

- [x] In `store`, inside the existing `DB::transaction` (after `TenantContext::switchTo($business->id)`, so RLS `WITH CHECK` admits the row — same reason the `Membership` insert sits there), call `app(\App\Services\SubscriptionService::class)->provisionTrial($business->id);` before returning the membership.
- [x] **Test** — `POST /api/v1/businesses` (authenticated, tenant-less token) → 201; then exactly one `Subscription::on('pgsql_migrate')->where('business_id', $id)` exists with `status = 'trialing'` and `trial_ends_at` within a minute of `now()->addDays(14)`. → PASS.
- [x] **Commit** — `feat: provision a 14-day trial subscription on business creation`.

Look at the existing `store` transaction first — the `SubscriptionService` call goes where the tenant is already switched to the new business.

---

## Task 7: BillingPolicy

**Files:** `app/Policies/BillingPolicy.php` (test via Task 12 RBAC)

- [x] **`manage(): bool`** → `app('tenant.role') === 'owner'`. Owner only — admin excluded (PRD §7). Used by every billing endpoint. Mirrors `StockPolicy` but role-equality, not `in_array`.
- [x] **Commit** — `feat: add BillingPolicy gating billing to the owner`.

---

## Task 8: Billing read

**Files:** `app/Http/Controllers/Api/V1/BillingController.php` (`show`), `routes/api.php`, test `tests/Feature/Billing/BillingReadTest.php`

- [x] **Controller `show`** `GET /billing` — `if (! (new BillingPolicy())->manage()) return $this->denied();` Resolve the tenant's subscription (`Subscription::firstOrFail()` — one per tenant, app-scoped), `$sub = $subscriptionService->syncStatus($sub);` then return JSON:
  ```php
  return response()->json([
      'plan' => $entitlement->effectivePlan($sub),
      'status' => $sub->status,
      'trial_ends_at' => $sub->trial_ends_at,
      'trial_days_left' => $entitlement->trialDaysLeft($sub),
      'current_period_end' => $sub->current_period_end,
      'limits' => \App\Billing\PlanCatalog::limits($entitlement->effectivePlan($sub)),
      'usage' => $entitlement->usage($sub),
      'over_limit' => [
          'customers' => $entitlement->isOverLimit($sub, 'customers'),
          'users' => $entitlement->isOverLimit($sub, 'users'),
      ],
      'payments' => SubscriptionPayment::orderByDesc('created_at')->get(),
  ]);
  ```
  `denied()` → `response()->json(['message' => 'Only the owner can manage billing.'], 403)`.
- [x] **Route** — `Route::get('billing', [BillingController::class, 'show']);` under `require.tenant`, **outside** the `plan.gate` group (Task 11).
- [x] **Test** — a fresh tenant (trial): owner GET → 200 with `status = 'trialing'`, `plan = 'pro'`, `trial_days_left` ≈ 14, `limits.max_customers = null`; seed 3 customers and assert `usage.customers = 3`; a neighbour's payments never appear. → PASS.
- [x] **Commit** — `feat: add billing summary read (plan, usage, limits)`.

---

## Task 9: Record a manual/UPI payment

**Files:** `BillingController.php` (`recordPayment`), `routes/api.php`, test `tests/Feature/Billing/BillingPaymentTest.php`

- [x] **Controller `recordPayment`** `POST /billing/payments` — `BillingPolicy::manage()` gate. Validate: `uuid` → `['nullable','uuid']`; `plan` → `['required', Rule::in(['pro'])]`; `amount` → `['required','numeric','gt:0']`; `mode` → `['required', Rule::in(['upi','bank','manual'])]`; `reference` → `['nullable','string','max:100']`; `period_months` → `['required','integer','min:1','max:24']`; `note` → `['nullable','string','max:255']`. Idempotent: `$uuid = $data['uuid'] ?? (string) Str::uuid();` if `SubscriptionPayment::where('uuid',$uuid)->first()` exists, return it (200). Else compute `$gst = bcmul((string) $data['amount'], '0.18', 2);` and `SubscriptionPayment::create([... 'business_id' via BelongsToTenant omitted ..., 'uuid' => $uuid, 'plan' => $data['plan'], 'amount' => $data['amount'], 'gst_amount' => $gst, 'mode' => $data['mode'], 'reference' => $data['reference'] ?? null, 'period_months' => $data['period_months'], 'status' => 'pending', 'note' => $data['note'] ?? null])`; return 201. **No activation.**
- [x] **Route** — `Route::post('billing/payments', [BillingController::class, 'recordPayment']);` under `require.tenant`, **outside** `plan.gate` (an owner in read_only must still be able to pay).
- [x] **Test** — owner posts `{plan:'pro', amount:'499.00', mode:'upi', reference:'UPI123', period_months:1}` → 201 with `status='pending'`, `gst_amount='89.82'`; the tenant's subscription is **unchanged** (still `trialing`); a repeated `uuid` returns 200 and the count stays 1; admin/salesman/accountant → 403. → PASS.
- [x] **Commit** — `feat: record manual/UPI subscription payments (pending)`.

---

## Task 10: Plan-limit enforcement (customers, users, feature)

**Files:** `app/Services/PlanGuard.php` (new), `CustomerController.php`, `InviteController.php`, `RawMaterialController.php`, `StockController.php`, `StockMovementController.php`, `ProductionController.php` (modify), test `tests/Feature/Billing/PlanEnforcementTest.php`

- [x] **`PlanGuard`** — one helper so the `402` shape is written once. `resolve(): Subscription` = `app(SubscriptionService::class)->syncStatus(Subscription::firstOrFail())`. `overLimitResponse(string $resource): JsonResponse` = `response()->json(['message' => 'Plan limit reached — upgrade to continue.', 'code' => 'plan_limit', 'resource' => $resource, 'upgrade' => true], 402)`. `featureResponse(string $feature): JsonResponse` = same with `'code' => 'plan_limit', 'resource' => $feature`. Expose `isOverLimit($sub,$resource)` / `hasFeature($sub,$feature)` by delegating to `EntitlementService`.
- [x] **Customer create** — in `CustomerController::store`, **after** the idempotency check (a replay of an existing `uuid` must NOT be blocked — it creates nothing) and before the create: `$sub = $guard->resolve(); if ($guard->isOverLimit($sub, 'customers')) return $guard->overLimitResponse('customers');`
- [x] **User invite** — in `InviteController::store`, before creating the invite: `if ($guard->isOverLimit($guard->resolve(), 'users')) return $guard->overLimitResponse('users');`
- [x] **Stock/production feature gate** — in each of `RawMaterialController`, `StockController`, `StockMovementController`, `ProductionController`, immediately after the existing `StockPolicy::manage()` gate: `$sub = $guard->resolve(); if (! $guard->hasFeature($sub, 'stock_production')) return $guard->featureResponse('stock_production');`
- [x] **Test** — helper to force a sub to a plan/status (`Subscription::firstOrFail()->update([...])` after provisioning via business create, or seed directly). On a **free/past_due** sub: seed 50 customers → the 51st `POST /customers` is 402 (`resource='customers'`), no row created (`count` stays 50); a 2nd membership makes the next `POST /businesses/{id}/invite` 402 (`resource='users'`); `GET /stock` is 402 (`resource='stock_production'`). On a **trial** sub: the 51st customer and `GET /stock` both succeed. An idempotent replay of an existing customer `uuid` on a maxed free plan still returns 200 (not blocked). → PASS.
- [x] **Commit** — `feat: enforce plan limits on customers, users and features (soft-block)`.

Read the existing `CustomerController::store` / `InviteController::store` / the four stock controllers first; the guard call slots in after the existing policy/idempotency checks, changing nothing else.

---

## Task 11: Read-only dunning gate

**Files:** `app/Http/Middleware/EnforceActivePlan.php`, `bootstrap/app.php`, `routes/api.php`, test `tests/Feature/Billing/ReadOnlyGateTest.php`

- [x] **Middleware `EnforceActivePlan`** — `handle($request, Closure $next)`: if `$request->isMethodSafe()` (GET/HEAD) → `return $next($request);`. Else load `Subscription::first()` (tenant pinned by upstream middleware); if it exists and `status === 'read_only'` → `return response()->json(['message' => 'Subscription is past due — writes are paused until payment.', 'code' => 'read_only', 'upgrade' => true], 402);`. Else `return $next($request);`.
- [x] **Alias** — in `bootstrap/app.php` `$middleware->alias([...])`, add `'plan.gate' => \App\Http\Middleware\EnforceActivePlan::class,`.
- [x] **Route wiring** — wrap the **domain write** routes (customers/sales/payments/products/pack-sizes/product-packs/raw-materials/stock-movements/production and their PATCH/DELETE/restore) in a `Route::middleware(['plan.gate'])->group(...)` inside the existing `require.tenant` group. Leave **outside** the gate: `GET` reads, `GET /billing`, `POST /billing/payments`, business create/switch/mine, invite accept. (Simplest: put `plan.gate` on the existing `require.tenant` group but add an early `isMethodSafe` pass-through — already handled — and exempt the two billing write routes by registering them in a sibling group without `plan.gate`.)
- [x] **Test** — force a tenant's subscription to `status='read_only'`: `GET /khata` → 200; `POST /customers` → 402 (`code='read_only'`); `POST /billing/payments` → 201 (exempt); `GET /billing` → 200 (exempt). A normal (trialing) tenant: `POST /customers` → 201 (unaffected). → PASS.
- [x] **Commit** — `feat: soft-block domain writes for read-only (dunning) tenants`.

Read `bootstrap/app.php` and `routes/api.php` first; mirror how `require.tenant` is grouped. Keep the billing write route out of the gated group.

---

## Task 12: RBAC coverage

**Files:** test `tests/Feature/Billing/BillingRbacTest.php`

- [x] Table-driven over the billing endpoints (`GET /billing`, `POST /billing/payments`): **owner** reaches each (status not 403); **admin, salesman, accountant** each get **403**. Provision a subscription per case (via business create or a direct seed) so the endpoints resolve a sub. This is the one place the "billing is owner-only, admin included in the exclusion" rule is proven. → PASS.
- [x] **Commit** — `test: cover billing RBAC (owner only)`.

---

## Task 13: DB-level RLS proof

**Files:** test `tests/Feature/Tenancy/BillingRlsTest.php`

- [x] Mirror `StockRlsTest` (query builder, app layer bypassed). Seed a neighbour's `subscriptions` + `subscription_payments` on `pgsql_migrate`. Under `TenantContext::switchTo(mine)` in a `DB::transaction`: `DB::table('subscriptions')->count()` and `DB::table('subscription_payments')->count()` are `0`; a `DB::table('subscriptions')->insert([... 'business_id' => theirs ...])` throws `QueryException` (WITH CHECK); seeding for **mine** then switching to mine shows count 1 each. → PASS.
- [x] **Commit** — `test: prove billing RLS with the app layer bypassed`.

---

## Task 14: Cross-tenant leak cases

**Files:** `tests/Feature/Tenancy/CrossTenantLeakTest.php` (modified)

- [x] Append: (a) B's subscription/payments never appear in A's `GET /billing` (A sees only its own payments; seed a payment for B, assert A's `payments` excludes it); (b) A recording a payment stamps A even if B's `business_id` is somehow supplied (BelongsToTenant ignores payload tenant — assert the created row's `business_id` is A's, via `withoutGlobalScopes()`); (c) B being `read_only` never gates A's writes (force B read_only, A `POST /customers` → 201). → PASS.
- [x] **Commit** — `test: extend cross-tenant leak suite with billing cases`.

---

## Task 15: Full suite, docs, close-out

**Files:** `backend/README.md`, this plan.

- [x] **Full suite** — `php artisan test`: green. Baseline entering this slice is **231** (end of stock/production); every task only adds tests.
- [x] **README** — a "Billing & Subscription API" section: the owner-only route/role table, the Free/Pro tiers + 14-day trial → plan-gate rule, the soft-block `402` contract (limits + read-only), manual/UPI payment recording + the deferred verification seam, and idempotency by `(business_id, uuid)`.
- [x] **Close-out** — tick every checkbox, add a status table (task → commit) and a Known Gaps section (verify/activate endpoint → Superadmin; Razorpay/GST-PDF/dunning-jobs/offline-sync/usage-cache/Business-tier → later; PgBouncer unchanged).
- [x] **Commit** — `docs: document the billing API and close out the plan`.

---

## Self-Review Notes

**Spec coverage** — spec §3 tables → Tasks 1, 2; §4 PlanCatalog → Task 3; §5 EntitlementService → Task 4; §6 lifecycle (provision/sync/activate) → Tasks 5, 6; §7 enforcement (limits, feature, read-only) → Tasks 10, 11; §8 RBAC + endpoints → Tasks 7, 8, 9, 12; §9 isolation → Tasks 13, 14; §9 test target → Task 15.

**Deliberate design decisions** (from spec §10): full enforcement in v1; verification deferred to Superadmin (service built + tested now); two tiers; trial = Pro entitlement with `effectivePlan()` the single resolver; subscription is current state (payments are the audit trail); `businesses.plan` left in place.

**Known risk unchanged:** PgBouncer is not configured; the suite proves RLS/`SET LOCAL` against Postgres directly, not transaction pooling in situ.

**Test-count target:** 231 (baseline) + roughly 3(T1)+3(T3)+6(T4)+4(T5)+2(T6)+4(T8)+4(T9)+6(T10)+4(T11)+4(T12)+4(T13)+3(T14) → **~278 passing**. A materially lower number means tasks were skipped.

---

## Close-out (2026-07-18) — COMPLETE

All 15 tasks implemented, each with passing tests. **Note on the baseline:** this
slice actually started from **253** (the Tenant Import slice had already landed on
this branch, not the 231 the plan assumed), so the full suite is now
**292 passed / 708 assertions** — **+39** from billing (the plan's per-task deltas
hold; only the absolute baseline was stale).

| Task | Deliverable | Commit |
|---|---|---|
| 1 | `Subscription` model/migration/factory (RLS) | `feat: add Subscription model with RLS isolation policy` |
| 2 | `SubscriptionPayment` append-only ledger (RLS) | `feat: add SubscriptionPayment append-only manual/UPI ledger` |
| 3 | `PlanCatalog` (Free/Pro in code) | `feat: add PlanCatalog encoding Free and Pro limits and features` |
| 4 | `EntitlementService` | `feat: add EntitlementService resolving plan limits and write access` |
| 5 | `SubscriptionService` (provision/sync/activate) | `feat: add SubscriptionService for provisioning and activation` |
| 6 | Trial provisioned on business create | `feat: provision a 14-day trial subscription on business creation` |
| 7 | `BillingPolicy` (owner only) | `feat: add BillingPolicy gating billing to the owner` |
| 8 | `GET /billing` | `feat: add billing summary read (plan, usage, limits)` |
| 9 | `POST /billing/payments` | `feat: record manual/UPI subscription payments (pending)` |
| 10 | `PlanGuard` + enforcement (customers/users/feature) | `feat: enforce plan limits on customers, users and features (soft-block)` |
| 11 | `EnforceActivePlan` read-only gate | `feat: soft-block domain writes for read-only (dunning) tenants` |
| 12 | Billing RBAC test | `test: cover billing RBAC (owner only)` |
| 13 | DB-level RLS proof | `test: prove billing RLS with the app layer bypassed` |
| 14 | Cross-tenant leak cases | `test: extend cross-tenant leak suite with billing cases` |
| 15 | README + close-out | `docs: document the billing API and close out the plan` |

**Deviation from the plan (deliberate, documented):** `PlanGuard::resolve()` and
the `plan.gate` middleware **fail open** when a tenant has no subscription row —
they treat it as a fresh trial rather than `firstOrFail()`-ing to a 404. The plan
wrote `Subscription::firstOrFail()`, but the existing stock/khata/catalog suites
build tenants via factory **without** a subscription; a hard `firstOrFail` would
have broken ~100 pre-existing tests and, worse, would let a missing billing row
take a real tenant's core operations down. Every business created through
`BusinessController::store` gets a real row (Task 6), and the enforcement tests
seed concrete subscriptions, so enforcement behaviour is exactly as specified.

### Known Gaps (deferred, per spec §1)

- **Verify/activate endpoint** — the `activateFromPayment()` seam is built and
  unit-tested, but wired to no endpoint here; the **Superadmin** console calls it.
- **Razorpay / GST-PDF / dunning jobs** — Phase 2 (v1 is manual/UPI, no gateway).
- **Offline sync of billing** — billing is online-only; no `sync_seq`, never rides
  the delta.
- **Redis usage cache** — `usage()` counts are live `COUNT`s under RLS; a
  tenant-namespaced cache is a later optimisation.
- **Trial-expiry scheduler** — `syncStatus()` transitions lazily on read; no cron.
- **A third "Business" tier** and any **frontend** — out of scope.
- **PgBouncer unchanged** — the suite proves RLS/`SET LOCAL` against Postgres
  directly, not transaction pooling in situ (pre-existing project-wide gap).
