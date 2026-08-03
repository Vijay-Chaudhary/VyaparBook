# VyaparBook — Multi-Tenant Khata & Distribution Platform
### Product Requirements & Technical Design · v2.0 (Multi-Tenant SaaS)

**Working title:** VyaparBook *(placeholder — see open questions)*
**Design partner / Tenant #1:** Shree Raj Shyama Ji Namkeen, Hata (Gorakhpur), UP
**Owner / Architect:** Vijay Kumar
**Supersedes:** v1.0 (single-business internal tool)
**Date:** July 2026

> **Note:** This PRD's §10 data-model sketch and §14 infra notes describe a Django + Docker stack. The project has since been directed to use **Laravel (PHP) with no Docker** instead — see `CLAUDE.md` and `docs/superpowers/specs/2026-07-04-tenancy-auth-core-design.md` for the authoritative stack decisions. The domain/product content below (tenancy model, RBAC, roadmap, etc.) still applies; treat framework-specific code snippets as illustrative only.

---

## 1. Repositioning: from one shop to a product

v1 assumed one business. This version makes it a **commercial multi-tenant SaaS**. The insight driving that: the khata/*बाकी* problem you live with is not specific to namkeen — it is the default operating reality of tens of thousands of small **food & FMCG manufacturers and distributors** across India: snacks, sweets, papad, spices, pickles, dairy, bakery. They all:

- make or pack goods and sell them **wholesale to retail (kirana) shops**,
- sell almost entirely **on credit**, tracked in a paper *bahi-khata*,
- chase outstanding on later visits,
- have zero software because existing ERPs are too heavy, English-only, and desktop-first.

**VyaparBook** is a mobile-first, Hindi-first, offline-capable khata + distribution app that any such business can sign up for and run in a day. Namkeen is the **beachhead vertical**; Shree Raj Shyama Ji is the reference tenant that de-risks the design.

**What this changes vs v1 (the load-bearing differences):**
1. **Tenant isolation** — every row belongs to a business; a bug must never leak one business's khata to another.
2. **Tenant-configurable catalog** — Senvda/Sev/Mix are now just the *default seed* for one tenant; each business defines its own products, pack sizes, and prices.
3. **Self-serve onboarding + billing** — signup, provision, invite staff, subscribe.
4. **Offline-first becomes tenant-aware** — the client caches one tenant; every queued mutation is tenant-stamped; sync enforces isolation.

Everything strong in v1 (offline-first, mobile-first UX, the Sales/Khata/Stock/Production domain) is retained and generalized.

---

## 2. Goals & Non-Goals

### v1 (of the multi-tenant product) — Goals
- **Multi-tenant core** with database-enforced isolation (see §4).
- **Self-serve onboarding**: signup → create business → seeded editable catalog → invite staff.
- **Domain modules**: Sales, Khata/Payments, Stock (raw material), Production — per tenant.
- **Offline-first PWA**, Hindi/English, cheap-Android friendly.
- **Basic subscription billing** (trial + one paid plan, India/Razorpay).
- **Superadmin console** to manage tenants and plans.

### Non-Goals (deferred)
- Supplier ledger, expenses, full analytics dashboard (Phase 2).
- GST e-invoicing, e-way bills.
- Native app-store builds (PWA is installable).
- Marketplace / B2B ordering between tenants.
- Complex usage-metered billing (start plan-based).

---

## 3. Tenant & Identity Model

```
Business (tenant) ─┬─< Membership >─ User        (a user may belong to >1 business)
                   ├─ owns ─ Product / PackSize / ProductPack   (tenant catalog)
                   ├─ owns ─ Customer / Sale / Payment
                   ├─ owns ─ RawMaterial / StockMovement / ProductionBatch
                   └─ has ─ Subscription / Plan
```

- **Business** = the tenant. Holds name, GSTIN (optional), default language, plan/subscription, settings.
- **User** = a person (phone/email + password/OTP). Global identity.
- **Membership** = User ↔ Business with a **role**. A user can own one business and be a salesman in another (rare, but the model allows it cleanly). The **active business** is chosen at login / via a switcher.
- **Roles within a tenant:** `owner` (full), `admin` (full minus billing), `salesman` (sales + payments + view khata), `accountant` (read + payments/ledger). v1 can ship `owner` + `salesman`.

Global identity + per-business membership avoids the classic mistake of tying a user to a single tenant, and makes staff invites and future multi-outlet businesses trivial.

---

## 4. Tenancy Isolation Model (the core decision)

Three standard options, judged for **this** product (many small tenants, high tenant count, modest per-tenant data):

| Model | Isolation | Ops at 1,000s of tenants | Verdict here |
|---|---|---|---|
| **DB per tenant** | Strongest | Painful — thousands of DBs, connection sprawl, migration fan-out, backup multiplication | ❌ Wrong for many tiny tenants |
| **Schema per tenant** | Strong | Migrations across N schemas get slow/fragile; connection & search_path juggling | ❌ Middling, scales poorly |
| **Shared schema + `tenant_id` + Postgres RLS** | Strong (DB-enforced) | One schema, one migration path, efficient pooling | ✅ **Recommended** |

**Recommendation: shared schema, `tenant_id` on every domain row, isolation enforced by PostgreSQL Row-Level Security.**

> **Superseded (all of §4, including the table above):** the product moved to **MySQL 8**, which has no row-level security. The shared-schema + `tenant_id` shape is unchanged and still correct; what changed is *what enforces it*. Isolation is now a single application layer — `App\Traits\BelongsToTenant`, which **fails closed** and throws when no tenant is bound — plus a test-environment query tripwire for raw builders. PgBouncer leaves the stack entirely. See `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`. The table row and the three subsections are retained for the reasoning behind the shared-schema choice, not as a description of the running system. **Every later mention of RLS, `SET LOCAL`, GUCs, PgBouncer or Postgres in this document is superseded by this note**, whether or not it is marked individually.

> **Note vs RepairOS:** RepairOS used *database-per-tenant* — correct there, because repair shops are fewer, heavier, and value hard isolation. VyaparBook targets a **high count of very small tenants**, where per-tenant DBs become an operational tax. Shared-schema is the right trade for this shape. ~~and it matches the RLS direction of your CRM spec.~~ (The argument is about the *schema* shape, which survived the MySQL move; the RLS half did not.)

### 4.1 RLS implementation ~~(superseded — no RLS on MySQL)~~
- Every tenant-owned table has `tenant_id UUID NOT NULL` (indexed; most queries filter on it).
- A per-connection setting carries the active tenant:
  ```sql
  -- policy on every tenant table
  ALTER TABLE sale ENABLE ROW LEVEL SECURITY;
  ALTER TABLE sale FORCE ROW LEVEL SECURITY;   -- applies even to table owner
  CREATE POLICY tenant_isolation ON sale
    USING (tenant_id = current_setting('app.current_tenant')::uuid)
    WITH CHECK (tenant_id = current_setting('app.current_tenant')::uuid);
  ```
- The app connects as a **non-superuser** role (superusers bypass RLS). Migrations run as a privileged role.

### 4.2 The PgBouncer gotcha (important) ~~(superseded — PgBouncer removed)~~
With **PgBouncer in transaction-pooling mode**, server connections are shared between clients *between transactions*, so a session-level `SET app.current_tenant` will leak or reset. The clean fix:

- Set the tenant with **`SET LOCAL` inside each transaction** (scoped to that transaction, auto-cleared on commit/rollback):
  ```sql
  BEGIN;
  SET LOCAL app.current_tenant = '<uuid>';
  -- ... queries ...
  COMMIT;
  ```
- In Django: wrap every request in an atomic block and set the GUC at its start (middleware + `connection.cursor().execute("SET LOCAL ...")`, or `ATOMIC_REQUESTS = True` plus a connection hook). This is compatible with transaction pooling and keeps pooling efficient.
- **Never** rely on session-level state behind a transaction pooler.

### 4.3 Tenant context propagation ~~(superseded — no GUCs; the tenant lives in the container)~~
- **HTTP:** middleware resolves the active business from the **JWT claim** (`tenant_id` + `membership_role`), verifies the membership, then `SET LOCAL app.current_tenant` for the request transaction.
- **Celery:** tasks receive `tenant_id` in their payload; a task base class opens a transaction and sets `SET LOCAL app.current_tenant` before running the body. No implicit/global tenant.
- ~~**Defense in depth:** RLS is the backstop, but the ORM layer *also* filters by tenant (a `TenantManager` default queryset) so bugs fail closed at two layers.~~ **There is no second layer now.** The ORM scope IS the isolation, which is why it was inverted to fail closed rather than fail open.

---

## 5. Onboarding & Provisioning

Self-serve, sub-5-minute:

1. **Signup** — phone (OTP) or email + password.
2. **Create business** — name, village/city, language (Hindi default), optional GSTIN.
3. **Seed catalog** — pick a template ("Namkeen / Snacks", "Sweets", "Spices", "Blank") → seeds default `Product` + `PackSize` + `ProductPack` rows, all **editable**. (Shree Raj Shyama Ji's Senvda/Sev/Mix + 15 pack sizes is the "Namkeen" template.)
4. **Import (optional)** — upload existing customers + opening outstanding from Excel/CSV.
5. **Invite staff** — send a link; they join the business as `salesman`.
6. **Start selling** — land on the New Sale screen.

Provisioning is just row inserts (shared schema) — no DB/schema creation, so onboarding is instant and cheap.

---

## 6. Tenant-Configurable Catalog (correctness-critical)

The single most important generalization: **nothing about products is hardcoded.**

- `Product`, `PackSize`, `ProductPack` are **tenant-owned**. A sweets maker has "Laddu / Barfi" in kg; a spice seller has "Haldi / Mirch" in 100g/200g/500g. Senvda/Sev/Mix is merely tenant #1's data.
- Templates give a fast start; every tenant edits freely.
- Pricing (`default_sell_price`, `default_cost_price`) is per tenant.
- The khata, sales, stock, and production logic are **domain-generic** and work unchanged across verticals — which is exactly why the product generalizes.

---

## 7. RBAC (per tenant)

| Capability | Owner | Admin | Salesman | Accountant |
|---|---|---|---|---|
| Create sales / returns | ✓ | ✓ | ✓ | — |
| Record payments | ✓ | ✓ | ✓ | ✓ |
| View customer khata | ✓ | ✓ | ✓ | ✓ |
| Edit/void sales | ✓ | ✓ | — | — |
| Manage catalog & prices | ✓ | ✓ | — | — |
| Stock & production | ✓ | ✓ | — | — |
| Invite/manage users | ✓ | ✓ | — | — |
| Billing & plan | ✓ | — | — | — |

Enforced server-side on every endpoint (role from the verified membership), never trusted from the client.

---

## 8. Billing & Subscription (India)

- **Plans** (illustrative): **Free** (1 user, ≤ 50 customers, core sales+khata), **Pro** (unlimited customers, up to 5 users, stock+production, WhatsApp reminders in P2), **Business** (multi-outlet, reports).
- **Gateway:** Razorpay (UPI/cards/netbanking) with Razorpay **Subscriptions** for recurring; **18% GST** on SaaS handled in invoicing.
- **Trial:** 14–30 days full-feature, then plan-gate.
- **Enforcement:** plan limits checked server-side (e.g., customer/user counts); soft-block with upgrade prompts, never data loss.
- **Dunning:** Celery Beat retries + reminders; grace period; downgrade to read-only, not delete.
- v1 can start minimal (trial + one paid plan, UPI/manual verification) and automate fully in Phase 2.

---

## 9. Offline-First × Multi-Tenant (the genuinely hard part)

Combining offline sync, tenant isolation, and money-ledger integrity is where most SaaS teams get burned. Design:

- **One active tenant per device session.** The client's IndexedDB (Dexie) is **namespaced by `tenant_id`**; only the active business's data is cached. Switching business requires the outbox to be flushed (synced) first, then the cache namespace swaps — no cross-tenant mixing, ever.
- **Every outbox mutation carries `tenant_id`** plus a client `uuid`. Uniqueness/idempotency is **`(tenant_id, uuid)`**.
- **Sync push:** server derives the tenant from the authenticated membership, ~~sets `SET LOCAL app.current_tenant`~~ **binds the tenant in the container for the request (`SetTenantContext`)**, and **rejects any mutation whose `tenant_id` ≠ the session tenant** ~~(belt-and-suspenders with RLS `WITH CHECK`)~~ — **this check is now the only one; there is no `WITH CHECK` underneath it.**
- **Sync pull:** delta since a per-tenant cursor, ~~RLS guarantees~~ **scoped by `business_id` through `BelongsToTenant`. An imperfect query is no longer caught by the database** — a raw builder that omits the predicate returns other tenants' rows, which is what the test-environment query tripwire exists to catch.
- **Append-only ledger** (sales, payments, returns as immutable entries; corrections are new voiding entries) → outstanding is always recomputable, and offline conflicts are near-eliminated.
- **Idempotency** ensures a sale/payment retried over a flaky link posts exactly once.

Net: the client is a fast per-tenant cache; the server ~~+ RLS are~~ **is** the source of truth and the isolation guarantee.

---

## 10. Data Model — Django Sketch (multi-tenant)

> **Superseded:** this slice is built in Laravel, not Django. See `docs/superpowers/specs/2026-07-04-tenancy-auth-core-design.md` §3 for the actual Eloquent/migration model. The sketch below is retained for domain-model reference only.

```python
class Business(models.Model):                 # the tenant
    id = models.UUIDField(primary_key=True, default=uuid4)
    name = models.CharField(max_length=120)
    city = models.CharField(max_length=80)
    gstin = models.CharField(max_length=15, blank=True)
    default_language = models.CharField(default="hi")
    plan = models.CharField(default="trial")

class User(AbstractUser):
    phone = models.CharField(max_length=15, unique=True)

class Membership(models.Model):
    user = models.ForeignKey(User, on_delete=models.CASCADE)
    business = models.ForeignKey(Business, on_delete=models.CASCADE)
    role = models.CharField(choices=[("owner","Owner"),("admin","Admin"),
                                     ("salesman","Salesman"),("accountant","Accountant")])
    class Meta: unique_together = ("user","business")

class TenantModel(models.Model):              # abstract base for all tenant data
    id = models.UUIDField(primary_key=True, default=uuid4)
    tenant = models.ForeignKey(Business, on_delete=models.CASCADE, db_index=True)
    updated_at = models.DateTimeField(auto_now=True)
    version = models.PositiveIntegerField(default=1)
    objects = TenantManager()                 # default filters by current tenant
    class Meta: abstract = True

class Product(TenantModel):        name_hi=…; name_en=…; base_cost_per_kg=…
class PackSize(TenantModel):       label=…; weight_kg=…; in_dropdown=…
class ProductPack(TenantModel):    product=FK; pack=FK; default_sell_price=…; default_cost_price=…
class Customer(TenantModel):       name=…; village=…; phone=…; opening_balance=…
class Sale(TenantModel):           uuid(unique per tenant); customer=FK; date=…; created_by=FK; status=…
class SaleLine(TenantModel):       sale=FK; product_pack=FK; qty(int, neg=return); rate=…
class Payment(TenantModel):        uuid; customer=FK; date=…; amount=…; mode=…
class RawMaterial(TenantModel):    name=…; unit=…; reorder_level=…
class StockMovement(TenantModel):  material=FK; date=…; kind(in/out/adjust); qty(signed)
class ProductionBatch(TenantModel):uuid; product=FK; date=…; output_kg=…
class MaterialConsumption(TenantModel): batch=FK; material=FK; qty=…
```

- `uuid` uniqueness is scoped per tenant: `unique_together = ("tenant","uuid")`.
- ~~RLS policies (from §4) applied to every `TenantModel` table via a migration that iterates the tenant tables.~~ **No policies exist; every tenant-owned model uses the `BelongsToTenant` trait instead.**
- Outstanding computed by aggregation, cached per customer in Redis (tenant-namespaced key).

---

## 11. API Design

- **Tenant resolution:** JWT carries `sub` (user), `tid` (active business), `role`. Middleware verifies membership, then ~~sets `SET LOCAL app.current_tenant`~~ **binds `tenant.id` in the container for the request**. Switching business = re-issue token with a new `tid`.
- All domain endpoints are implicitly tenant-scoped (no `tenant_id` in the path; it comes from the token).

```
POST /api/auth/otp/request           POST /api/auth/otp/verify
POST /api/businesses                 # create tenant (onboarding)
GET  /api/businesses/mine            # businesses this user belongs to (switcher)
POST /api/businesses/{id}/switch     # re-issue token for a business
POST /api/businesses/{id}/invite     # invite staff
GET  /api/catalog                    # tenant products/packs/prices
GET  /api/customers   POST /api/customers   GET /api/customers/{id}/statement
POST /api/sales        PATCH /api/sales/{id}          # idempotent (tenant,uuid); void
POST /api/payments                                    # idempotent
GET  /api/stock        POST /api/stock/movements
POST /api/production
GET  /api/sync?since={cursor}        POST /api/sync    # delta pull / bulk push
# platform:
GET  /api/admin/tenants   ...        # superadmin only
```

---

## 12. Superadmin / Platform Console

A separate, role-gated surface for you (the operator): list tenants, plan/subscription status, usage (customers/sales counts), suspend/reactivate, impersonate-for-support (audited), trigger per-tenant export/backup, and platform metrics. Not part of any tenant's data; guarded by a platform-admin flag, not a business membership.

---

## 13. Security, Isolation & Compliance

- **Isolation:** ~~RLS (DB-enforced) + ORM tenant filter (app-enforced) = two independent layers; a single-layer bug fails closed.~~ **One layer, app-enforced: the `BelongsToTenant` global scope, which throws rather than returning unscoped rows when no tenant is bound. There is no DB-enforced layer behind it — see §4's superseded note.**
- **Auth:** OTP/phone-first (India), JWT with short expiry + refresh; role checks server-side.
- **Transport:** HTTPS everywhere (Caddy auto-TLS).
- **India DPDP Act (2023):** per-tenant **data export** and **delete/erasure**, consent on signup, data-processing terms. Shared-schema means delete = tenant-scoped row purge (script + verification).
- **Auditability:** append-only ledger; admin/impersonation actions logged.
- **Backups:** nightly full ~~`pg_dump`~~ **`mysqldump`**; per-tenant logical export on demand (~~RLS-scoped dump~~ **`business_id`-scoped, via `TenantExporter`**) for portability and offboarding.
- **Idempotency & rate limits:** per-tenant, to contain a noisy tenant.

---

## 14. Scaling & Operations

> **Note:** the PRD's Docker-based infra below is superseded for this project — no Docker; see `CLAUDE.md`. The Celery references translate to Laravel Queues + a `TenantAwareJob` base in the actual implementation.

- **Connections:** ~~PgBouncer (transaction pooling) with the `SET LOCAL` tenant pattern (§4.2). One Postgres scales~~ **No pooler — PgBouncer was in the stack only for transaction-pooled GUCs that no longer exist. One MySQL 8 instance scales** to thousands of small tenants; shard later only if needed.
- **Redis:** keys namespaced `t:{tenant_id}:…`; cache + Celery broker.
- **Celery:** tenant-in-payload tasks; separate queues for interactive vs heavy (reports, WhatsApp) work; per-tenant concurrency caps to prevent noisy neighbors.
- **Noisy-neighbor:** per-tenant rate limits, query timeouts, and (later) heavy tenants isolated to their own worker pool.
- **Observability:** metrics tagged by `tenant_id` (careful with cardinality — bucket small tenants), per-tenant error tracking.
- **Infra:** ~~Docker Compose to start (Django/DRF, Postgres, PgBouncer, Redis, Celery, Next.js, Caddy) — same shape as RepairOS; move to managed Postgres~~ **Native local services per `CLAUDE.md` (Laravel, MySQL 8, Redis); move to managed MySQL** + horizontal API scaling as tenants grow.

---

## 15. Mobile-First UX (multi-tenant additions)

Everything from v1 (thumb-zone layout, bottom tabs, numeric keypads, Hindi-first, cheap-phone budget, installable PWA) plus:

- **Onboarding flow** (signup → create business → template → invite) designed for a non-technical owner on a phone.
- **Business switcher** in the header for users in >1 business (rare, but clean).
- **Plan/upgrade** surfaces that never block data entry — soft prompts only.
- **Per-tenant branding-lite:** business name + optional logo on statements/reminders.

Screens add: Signup/OTP, Create Business, Choose Template, Invite Staff, Plan & Billing, Business Switcher — on top of Home / Sales / Khata / Stock / Production.

---

## 16. Localization

Hindi (default) + English via `next-intl`; **per-tenant default language** with per-user override. Devanagari-safe fonts, `₹` Indian grouping, `dd-MMM-yyyy`. Room to add regional languages (Bhojpuri/Marathi/Gujarati/Tamil) as verticals expand — a real moat vs English-only ERPs.

---

## 17. Migration / Tenant #1

Shree Raj Shyama Ji is imported as the first Business:
1. Create the business, "Namkeen" template seeds Senvda/Sev/Mix + 15 pack sizes (then adjust prices).
2. Import ~40 customers with village + **opening outstanding** (from the Customer Ledger बाकी) so khata continues seamlessly.
3. Import raw materials + current stock as opening `StockMovement`s.
4. Optionally backfill Daily Sales history.

A Django management command reading the existing `.xlsx` (openpyxl) does this per tenant — and doubles as the generic "import from Excel" onboarding feature for every future tenant.

> **Note:** the import tooling itself will need to be re-implemented as a Laravel Artisan command (using e.g. `maatwebsite/excel`) rather than a Django management command, when this slice is built.

---

## 18. Roadmap

**Phase 1 — Multi-tenant core (this doc):** tenancy ~~+ RLS~~ **(app-enforced)**, onboarding, tenant catalog, Sales/Khata/Stock/Production, offline PWA, Hindi/English, basic billing, superadmin, Excel import.

**Phase 2:** Supplier ledger + purchases, Expenses, analytics dashboard (port the Excel KPIs, per tenant), **automated WhatsApp reminders** (Celery + WhatsApp Business API), full self-serve billing/dunning, staff roles (accountant), reports.

**Phase 3:** GST invoicing, salesman route/beat planning, actual cost-per-kg from production, finished-goods packed inventory, ~~Tally/accounting export~~, more verticals (sweets/spices templates), ~~regional languages~~.

> **Phase 3 status (2026-07-25).** Done: actual cost-per-kg (shipped as reporting Phase 2b), sweets/spices templates, finished-goods inventory (F-09), GST invoicing (F-10).
> **Dropped:** Tally/accounting export and regional languages — owner decisions, not deferrals; do not re-plan them.
> **Phase 3 is complete.** Beat planning shipped as F-11; English and Hindi remain the supported locales.

**Phase 4:** Multi-outlet businesses, B2B reorder links between tenants, analytics benchmarking, marketplace ideas.

---

## 19. Risks

- **Tenant leakage** — ~~mitigated by two-layer isolation (RLS + ORM), `WITH CHECK`, and~~ **now a materially larger risk than this doc originally assumed: one app layer (`BelongsToTenant`, fail-closed), the test-environment query tripwire for raw builders, and** a dedicated cross-tenant test suite that *tries* to leak and must fail.
- ~~**PgBouncer/RLS misuse** — the `SET LOCAL`-per-transaction pattern must be enforced centrally (one base transaction wrapper), never ad hoc.~~ **Replaced by: bypass creep.** `Tenancy::withoutTenant()` is the only sanctioned way past the scope; the sites are enumerable with `grep -rn withoutTenant` and each one must stay justified. Raw builders (`DB::table()`), `fresh()`/`refresh()`, and `unique:`/`exists:` rules all walk past the scope — see `CLAUDE.md`.
- **Offline + tenant switch** — enforce "flush before switch"; forbid mixed-tenant outbox.
- **Billing complexity in India** — start plan-based + UPI; avoid metered billing until demand proves it.
- **Vertical creep** — keep the domain generic; resist per-vertical special-casing beyond templates.

---

## 20. Open Questions (before build kickoff)

1. **Product name & brand** — VyaparBook is a placeholder. Keep Hindi-flavored (VyaparBook / DukaanKhata / BikriBook) or neutral?
2. **Isolation confidence** — comfortable with **shared-schema + RLS** (my recommendation), or do you want **schema-per-tenant** for stronger perceived isolation despite the ops cost? — *Resolved: shared-schema, but on MySQL 8 with **no** RLS — isolation is app-enforced only. See §4's superseded note.*
3. **User ↔ business** — support a user belonging to **multiple businesses** in v1, or lock to one-business-per-user to simplify? — *Resolved: multiple businesses supported, see the tenancy/auth core spec.*
4. **Billing in v1** — ship real Razorpay subscriptions in v1, or trial + manual/UPI now and automate in Phase 2?
5. **Verticals at launch** — namkeen-only templates for v1, or seed sweets/spices templates too to test generality early?
6. **Auth** — phone-OTP-first (best for this audience) — confirm, and pick the OTP provider (MSG91 / Twilio / Firebase). — *Resolved for this slice: phone OTP + email/password both supported; OTP delivery stubbed (console/log) until a provider is chosen, see the tenancy/auth core spec.*

---
*End of document · v2.0 (Multi-Tenant SaaS)*
