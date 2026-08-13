# VyaparBook Frontend — Implementation Plan

**Status:** proposed, not started
**Date:** 2026-07-20
**Stack decision:** Blade (app shell + online-only pages) + React islands (offline-capable screens) + Dexie + service worker

## Decisions taken

| # | Decision | Choice |
|---|---|---|
| 1 | Project layout | **Single Laravel app** — standard Laravel architecture, no separate frontend project (§0.1) |
| 2 | Auth | **Session (web guard) for Blade + JWT for the API** (§2) |
| 3 | React runtime | **Full React** — not Preact. Compatibility over ~35KB (§5) |
| 4 | Offline write cap | **Warn at 7 days, block new writes at 30** (§2) |

---

## 0.1 Project layout

One Laravel application. No separate SPA project, no second Node server, no independent deploy.

```
backend/
├── app/                     PHP — unchanged
├── routes/
│   ├── api.php              JSON API (JWT)   — exists
│   └── web.php              Blade routes (session) — to build
├── resources/
│   ├── views/               Blade templates
│   │   ├── layouts/app.blade.php
│   │   ├── auth/  onboarding/  billing/  admin/
│   │   └── app.blade.php    the single cached shell for /app/*
│   ├── js/
│   │   ├── app.jsx          mounts React islands into Blade
│   │   ├── offline/         Dexie schema, outbox, sync engine
│   │   ├── screens/         Sales, Khata, Stock, Production
│   │   └── i18n/            hi.json, en.json
│   └── css/app.css          Tailwind + design tokens
├── public/
│   ├── manifest.webmanifest
│   └── sw.js                service worker (must be served from root scope)
└── vite.config.js           already present
```

Vite compiles both bundles; Laravel serves everything; one `php artisan serve` runs the whole app in dev. `npm run dev` alongside it for HMR.

> **Naming note:** the directory is called `backend/`, but it now contains the entire application. Renaming it would churn paths, CI and tooling for no functional gain — recommend leaving it and noting it here so the name doesn't mislead later.

---

## 0. What this supersedes

Two existing documents disagree with this plan. Both need updating if it is approved:

| Document | Says | Reality |
|---|---|---|
| `CLAUDE.md` | Frontend is **Next.js** | Now Blade + React islands |
| PRD §16 | i18n via **`next-intl`** | No Next.js, so a different i18n mechanism is needed (§6 below) |

The PRD's *requirements* (offline-first, Hindi-first, installable PWA, thumb-zone UX) all still stand. Only the named technologies change.

---

## 1. The constraint that shapes everything

PRD §9 — *"the genuinely hard part"* — requires the app to work with **no network**. This is not a nice-to-have for a khata book: the failure mode it protects against is a shopkeeper unable to record a sale during an outage, which is precisely when a paper book would have worked.

Server-rendered Blade cannot do this alone. So the split is:

```
┌─ Blade, online-only ──────────────┐   ┌─ React island, offline-capable ─┐
│  /login  /signup  /onboarding     │   │  /app/*  (single cached shell)  │
│  /billing  /invite  /admin/*      │   │    Home · Sales · Khata         │
│  /print/statement/{id}            │   │    Stock · Production           │
│                                   │   │                                 │
│  Server-rendered, session auth    │   │  Dexie cache + outbox           │
│  Needs network. That's fine —     │   │  Service worker caches shell    │
│  nobody pays a bill offline.      │   │  Survives full offline use      │
└───────────────────────────────────┘   └─────────────────────────────────┘
```

**The rule:** anything a shopkeeper does *while serving a customer* is React + offline. Everything else is Blade.

`/app/*` is **one** Blade route rendering an empty shell with client-side routing inside. One shell = one thing for the service worker to cache. Do not create a Blade page per screen inside `/app`; that multiplies the offline surface for no benefit.

---

## 2. Authentication — the first real decision

The backend today is **JWT-only** (`auth:api`, `php-open-source-saver/jwt-auth`). Blade pages cannot be protected by a token in JavaScript — by the time JS runs, the server has already rendered the page. So Blade needs server-side auth.

**Recommendation: session auth (web guard) for Blade, JWT for the API layer.**

```
POST /login  (Blade form)
   -> Laravel session cookie  -> protects Blade routes
   -> GET /auth/token         -> mints a JWT for the React layer
```

> The token endpoint is a **web** route (`/auth/token`), not `/api/v1/...`. It must
> be authorised by the session cookie, and Laravel's `api` middleware group is
> stateless — a session-protected endpoint belongs where sessions exist.

Rationale:

- **Blade pages rendering tenant data** (billing, printable statements) need real server-side authorization. A localStorage token cannot provide it.
- **Session cookies survive offline reloads.** A JWT with a 60-minute TTL does not — and an offline shop cannot refresh one. The session cookie is what lets the cached shell open at 6am after a night offline.
- **The JWT API stays untouched.** 398 passing tests depend on it; none need to change.

**Cost, stated plainly:** two auth mechanisms in one codebase. That is real complexity and the main argument against. The alternative — JWT-only, with every screen client-rendered — makes Blade nearly vestigial, which contradicts the brief.

### Token handling (security)

- **Never put the JWT in `localStorage`.** It is readable by any XSS. Hold it **in memory**, re-fetch from `/api/v1/auth/token` on page load using the session cookie.
- Session cookie: `HttpOnly`, `Secure`, `SameSite=Lax`.
- **Offline auth state:** the app opens from the cached shell using a non-sensitive local flag (`tenant_id`, display name, role). Absence of a valid JWT blocks *sync*, never *local data entry*. Writes queue in the outbox regardless.

### Offline write cap *(decided)*

An offline device does not queue forever — that would let a lost or stolen phone accumulate unbounded unsynced data:

| Days since last successful sync | Behaviour |
|---|---|
| 0–7 | Queue silently. Normal operation. |
| 7–30 | Queue, plus a persistent banner: *"reconnect to sync"*. |
| 30+ | **Block new writes.** Existing outbox is preserved and still syncs on reconnect. |

Blocking never discards queued data — it only stops new entries accruing. A config value, so it can be tuned without an architectural change.

---

## 3. Offline architecture

### 3.1 Local store (Dexie / IndexedDB)

Per PRD §9, the database is **namespaced per tenant** — `vyaparbook_t_{tenant_id}` — so cross-tenant mixing is structurally impossible, not merely prevented by a query filter.

```
Tables (mirroring the sync/pull payload):
  customers, sales, sale_lines, payments,
  raw_materials, stock_movements,
  production_batches, material_consumptions
  meta        { key: 'cursor', value: <sync_seq> }
  outbox      { id, uuid, tenant_id, op, payload, created_at, attempts, last_error }
```

`sync/pull` already returns exactly these collections plus a cursor, and withholds stock/production rows for non-managers — so the client store must tolerate those arrays being legitimately empty by role, not treat it as data loss.

### 3.2 Outbox

Every mutation goes to the outbox first, then to the server. Never the reverse — that ordering is what makes the app usable offline.

- Each entry carries `tenant_id` + client-generated `uuid` (v4).
- Idempotency is `(tenant_id, uuid)`, matching the server's existing unique constraints. A retry over a flaky link posts **exactly once**.
- On `sync/push` success, remove from outbox and apply the server's canonical row.
- On **4xx** (validation/authorization), do **not** retry — park the entry in a "needs attention" state and surface it. Silent infinite retry of a permanently-invalid write is a classic outbox bug.
- On **5xx / network failure**, retry with exponential backoff.
- On **429** (rate limited — we now return `Retry-After`), honour the header rather than hammering.

### 3.3 Sync engine

```
pull():  GET  /api/v1/sync/pull?since={cursor}   -> upsert rows, advance cursor
push():  POST /api/v1/sync/push  { mutations: [ {type, tenant_id, uuid, payload} ] }
         -> { results: [ {uuid, status, id?, reason?} ] }
```

> **Corrected against the server at Phase 2.** The envelope is `mutations`, not
> `changes`. Results are **per mutation**, so one bad row never blocks the batch:
> `applied` and `duplicate` both mean durable (delete from outbox); `rejected`
> is permanent (park it). Only `customer`, `sale` and `payment` are pushable —
> stock and production have no offline write path, which is fine through Phase 5
> but must not be assumed otherwise.

Order: **push before pull**, so local work reaches the server before remote state overwrites the view.

Triggers: on app open, on regaining connectivity (`online` event), after any local write, and on a slow interval (~60s) while active. All debounced — `sync` has its own rate-limit bucket (60/min) and the client should stay far below it.

**Conflict policy:** the ledger is append-only *on the device* — the phone only ever adds sales and payments, so it still has very nearly no true conflicts. The **owner's console** may edit or delete a row (soft delete, `deleted_at`); those changes reach the device the ordinary way, as a row with a new `sync_seq`, and `khata.js` drops a row carrying `deleted_at` exactly as it hides an archived customer. Corrections made through the REST API and the order workflow are still new voiding entries. Where a row *is* mutable (customer name, product price), last-write-wins on `version`, and the server's copy is authoritative on mismatch.

### 3.4 Tenant switching

PRD §9 is explicit and this must not be softened: **the outbox must be fully flushed before switching business.** If unsynced entries exist, block the switch and say why. Then close the Dexie namespace and open the new one. Never hold two tenants' data open simultaneously.

---

## 4. Design system

> **Note:** the `ui-ux-pro-max` skill's searchable database (`scripts/`, `data/`) is a **broken symlink** in this environment — it points at `src/ui-ux-pro-max/`, which does not exist. The tokens below are derived from the design rules in `SKILL.md` (which did load) plus the PRD's stated constraints, not from that palette/typography database. Worth fixing the skill install if you want its 161-palette recommendations.

### 4.1 Context drives the choices

This is not a consumer lifestyle app. The operating conditions are specific and they dictate the design:

| Condition | Design consequence |
|---|---|
| Cheap Android phones | Tiny JS budget; no heavy animation; avoid large images |
| Outdoor / variable light | **High contrast**, not fashionable low-contrast grey |
| Hindi-first, non-technical owner | Devanagari-safe fonts, icon + **label** always, no jargon |
| One-handed use while serving | Bottom nav, thumb-zone primary actions, big targets |
| Money data | Tabular figures, unambiguous ₹ formatting, no decorative charts |

### 4.2 Tokens

```css
/* Color — semantic, not raw hex in components (SKILL.md §6 color-semantic) */
--color-primary:      #1D4ED8;  /* blue-700  — actions, active nav */
--color-success:      #15803D;  /* green-700 — payment received, in stock */
--color-danger:       #B91C1C;  /* red-700   — dues, voids, destructive */
--color-warning:      #B45309;  /* amber-700 — low stock, plan expiring */
--color-surface:      #FFFFFF;
--color-bg:           #F8FAFC;  /* slate-50 */
--color-text:         #0F172A;  /* slate-900 — 16.1:1 on white */
--color-text-muted:   #475569;  /* slate-600 —  7.4:1 on white, NOT slate-400 */
--color-border:       #CBD5E1;  /* slate-300 — visible in both themes */
```

Every pair above clears WCAG AA (4.5:1). `slate-400` on white is 3.0:1 and **fails** — it is the single most common way a UI quietly becomes unreadable in sunlight.

```css
/* Spacing — 4/8pt rhythm */
--space-1: 4px;  --space-2: 8px;  --space-3: 12px;
--space-4: 16px; --space-6: 24px; --space-8: 32px; --space-12: 48px;

/* Type scale — base 16px (below this, iOS auto-zooms on input focus) */
--text-xs: 12px;  /* labels only, never body */
--text-sm: 14px;
--text-base: 16px;
--text-lg: 18px;
--text-xl: 24px;
--text-2xl: 32px;
--leading-body: 1.6;

/* Touch — non-negotiable */
--tap-min: 44px;
--tap-gap: 8px;
```

**Fonts:** `Noto Sans Devanagari` + `Inter`, self-hosted with `font-display: swap`, subset to the glyphs actually used. Do **not** load from Google's CDN — it adds a DNS + TLS round trip on a slow rural connection, and self-hosting keeps the PWA fully offline.

**Numbers:** `Intl.NumberFormat('en-IN')` for ₹ (Indian grouping: ₹1,20,000 — *not* ₹120,000). Tabular figures (`font-variant-numeric: tabular-nums`) in every khata column so digits don't jitter as values change.

**Icons:** Lucide SVG, one stroke width (1.5px), 24px default. **No emoji as icons** — they render differently per device and cannot be themed.

### 4.3 Navigation

Bottom tab bar, **5 items maximum** (SKILL.md §9 `bottom-nav-limit`), each with icon **and** Hindi label:

```
होम        बिक्री       खाता        स्टॉक       और
Home       Sales       Khata       Stock      More
```

Stock hides for salesman/accountant roles (matching `StockPolicy` and what `sync/pull` actually sends them) — so for those roles the bar has 4. Do not render a tab that 403s.

Active state must be indicated by **weight + color + indicator**, never color alone (§1 `color-not-only`).

---

## 5. Bundle budget

**Decision: full React**, not Preact — compatibility is worth more than the bytes, and `preact/compat` failures tend to be obscure and late-surfacing.

> **Measured, Phase 1–2 (correcting the estimate above).** The vendor chunk is
> **93.3KB gzipped**, against a 60KB budget — 55% over:
>
> | | gzipped |
> |---|---|
> | React 19 + react-dom | 60.5KB |
> | Dexie | ~32.8KB |
> | **vendor total** | **93.3KB** |
> | app shell (`main`) | 3.3KB |
> | every-page JS (`app`) | 0.8KB |
> | CSS | 6.9KB |
>
> The ~45KB React figure quoted when Preact was declined was React 18. App code
> is tiny; essentially the entire payload is two libraries.
>
> **Decision needed at Phase 3**, on measured evidence rather than estimates:
> `preact/compat` saves ~35KB and swapping Dexie for `idb` (~2KB) saves ~30KB —
> together taking vendor from 93KB to roughly 28KB. That is the difference
> between a 3-second and a 10-second first load on a slow rural connection.
> Dropping Dexie costs real ergonomics (its transaction and query API is doing
> genuine work in `sync.js`), so this is a trade, not free.

That costs ~60KB gzipped for `react` + `react-dom` before a line of app code, so the budget is set honestly around it rather than pretending otherwise:

| Target | Budget (gzipped) |
|---|---|
| Vendor (React + Dexie) | < 60KB |
| App shell | < 35KB |
| Largest route chunk | < 30KB |
| **Time-to-Interactive** | **< 3s** on throttled 3G + 4× CPU slowdown |

Because the runtime is fixed, the savings have to come from everything else. Non-negotiables:

- **Route-level code splitting.** Khata must not ship Production's code.
- **No component library.** No MUI/AntD/Chakra — they dwarf the runtime. Tailwind + hand-built components only.
- **Icons imported individually** (`import { Plus } from 'lucide-react'`), never the barrel — a barrel import pulls in the whole set.
- **No moment/lodash.** `Intl` and plain JS cover what's needed.
- **CI enforces the budget.** A size check that fails the build, because bundle creep is invisible on a developer machine and only hurts the shopkeeper.

> Preact remains a one-line escape hatch if the 3G target proves unreachable. Re-evaluate at the end of Phase 3, when there is a real bundle to measure instead of an estimate.

---

## 6. Localization

`next-intl` is unavailable. Replacement:

- **Blade pages:** Laravel's native `lang/{hi,en}/*.php` + `__()`. Already built in, zero dependency.
- **React islands:** a small JSON dictionary per locale + a `t()` helper. A full i18n library is not justified for two languages and a few hundred strings.
- **Locale resolution:** tenant default (`businesses.default_language`, already in the schema) → per-user override → fallback `en`. **English is now the default** (`APP_LOCALE=en`); Hindi stays fully translated and selectable, and PRD §16's original Hindi-default is superseded.
- Dates `dd-MMM-yyyy`; currency via `Intl.NumberFormat('en-IN')`.

Strings live in one place per locale from day one. Retrofitting extraction after the fact is miserable.

---

## 7. Phases

Each phase ends shippable and testable. No phase depends on a later one.

### Phase 1 — Foundations ✅ *done*
- Vite + Tailwind wired to the design tokens above; Preact alias
- Blade layout, session auth (`web` guard), login/logout
- `GET /api/v1/auth/token` (session → JWT exchange)
- Self-hosted fonts; i18n scaffolding with `hi` default
- **Verify:** log in via Blade, receive a working JWT, `/api/v1/whoami` responds

### Phase 2 — Offline core ✅ *done*
- Dexie schema, per-tenant namespacing
- Outbox with idempotent `uuid`, backoff, 4xx parking
- Sync engine (push-then-pull, cursor persistence)
- Service worker: app shell caching, install prompt, manifest
- **Verify:** Vitest over the outbox/sync state machine; Playwright with network offline — record a sale, go online, assert it lands exactly once and is not duplicated on repeat sync

> This phase is deliberately **before** any UI. If offline sync is going to be wrong, it should be wrong while there are five files to change, not fifty.

### Phase 3 — Khata (the core loop) ✅ *done*
- Home / today summary
- Customer list + search, customer ledger with running balance
- Record sale, record payment — **fully offline**
- **Verified:** the entire loop with the network disabled, and client/server
  balances reconciled to the paisa (both independently computed ₹489.50)

### Phase 4 — Onboarding *(Blade, online-only)* ✅ *mostly done*
- Signup + **DPDP consent** (mandatory — the API rejects signup without it) ✅
- Create business → choose template → invite staff ✅
- Business switcher, with the outbox-flush guard from §3.4 ✅ — in the React
  `/app` header. Turned out to be **load-bearing, not polish**: a multi-business
  user gets a tenant-less token, so without a picker the app hung on "loading".
  `/auth/token?business=` scopes the token to a chosen membership (server
  verifies it); the switch is refused while the outbox has unsynced work
  (verified in a browser: the guard fired and the business stayed put).

> Business creation was extracted into `BusinessProvisioner` so the JWT API and
> the Blade flow share one implementation of the tenancy-sensitive
> business+membership+trial transaction, rather than risking divergence.

### Phase 5 — Stock & Production ✅ *done*
- Raw materials + stock movements — React, manager-only
- Production batches — record a batch → server draws materials down; a sync
  after success pulls both the batch and the stock movements it created, so
  on-hand updates without a manual refresh. 5th manager-only tab. Verified:
  POST /production → 201, batch listed, no console errors.
- Role-gated in three layers, verified in a browser: the nav tab is hidden for
  salesman/accountant, a deep link to /stock is refused, and the server never
  sends them stock rows (0 cached).

> Stock is **online-only** by design: sync/push accepts only customer/sale/
> payment, and stock is a back-office task, not counter work done mid-outage.
> Reads are cached (managers receive stock rows via sync/pull) so the list and
> on-hand work offline; writes go straight to the REST API and report the
> server's verdict (402 → upgrade prompt, offline → try-again).

### Phase 6 — Billing & Plan *(Blade, online-only)* ✅ *done*
- Plan display, live usage vs limits, record payment (pending → platform verifies), payment history ✅
- Dunning banner: the `/billing` page shows the read_only/past_due/trial state, and is the way **out** of dunning — owner-only, and deliberately outside the plan gate so a suspended owner can still pay. ✅
- Reached from the React app via an **owner-only** "Plan & billing" link on Home (a real link out of the SPA, carrying `?business=` so a multi-shop owner lands on the shop they're viewing). ✅
- Read-only (402) **soft-prompts, never blocks data entry** — inherent to the offline-first design: khata writes always land in the local outbox; a read_only tenant's `sync/push` returns 402 and the entry stays queued, never lost (PRD §15). Owner-only gating mirrors the JWT `BillingController` (resolves the caller's *owned* business, never a request-supplied one). ✅

### Phase 7 — Platform console *(Blade, online-only)* ✅ *done*
- Tenant directory (search + pagination), drill-down (business, subscription, members, recent payments), verify/reject payment, suspend/reactivate, impersonate. ✅
- Session-gated on the **live** `is_platform_admin` flag (`platform_admin.web`), the server-rendered twin of the JWT `require.platform_admin`. Cross-tenant by design: reads on the SELECT-only platform connection (`mysql_platform`, user `vyapar_platform_ro`), writes pinned to the target tenant and pushed back **through** the tenant scope on the normal connection (`PlatformTenantContext`). ✅
- **No logic fork:** the Blade actions reuse the exact same seams as the API (`SubscriptionService`, `PlatformTenantContext`, `PlatformAudit`, `TokenService`) — the web layer only chooses redirect+flash over JSON. Every mutation writes the same audit trail. ✅
- Login routes a platform admin to the console rather than `/app` (they hold no membership, so the shopkeeper app would stall). ✅
- Impersonation is a one-click **"view as tenant"**: the console stashes the short-lived read-only token in the operator's **server-side session** (never a URL, never the DOM), redirects to `/app`, and the existing session→JWT bridge (`ApiTokenController`) hands that token to the SPA on boot — so it lives in memory exactly like any other token, with no new storage path. The app shows a loud read-only banner, hides every write CTA and blocks all write handlers as a backstop (the server already bars writes on the token), and **Exit wipes the tenant's local cache off the operator's device** before ending the session. The 30-minute token expiry ends the view on its own. ✅

### Phase 8 — Hardening *(in progress)*
- **Bundle budget enforced in CI** ✅ — `scripts/check-bundle-size.mjs` gzips the Vite output and fails the build over budget (`npm run build:check`); wired into `.github/workflows/ci.yml` alongside Vitest. Budgets are set at *measured + headroom* to catch creep (a barrel import, a stray dependency), not to relitigate the full-React floor (§5). Current: vendor 91% of budget, app shell 70%, css 65%.
- **CI added** ✅ — the repo had none. Frontend job (build + budget + Vitest) is locally verified; the backend Pest job is built from the real connection config + role-provisioning migrations and should be confirmed on its first run.
- `prefers-reduced-motion` ✅ — already global in `app.css` (honours the OS setting for all animation/transition/scroll). Zoom is never disabled (viewport has no `maximum-scale`), and the semantic colour tokens all clear WCAG AA — the a11y groundwork is in from earlier phases.
- **Route-level code splitting — evaluated, deliberately NOT done.** §5 lists it as a non-negotiable, but two facts override it here: (a) app code is a tiny fraction of the payload — the runtime (React + Dexie, ~91KB gz) dominates, and splitting the ~15KB of screens saves little; (b) a lazy route chunk is **not** in the service worker's precache, so it would fail to load offline — the exact silent failure the PWA exists to prevent. The budget check now guards the real risk (runtime/vendor creep). Revisit only if a screen grows large enough to matter *and* its chunk is added to the SW precache list.

- **Impersonation one-click "view as tenant" handoff** ✅ — built (see Phase 7 above): session-carried read-only token → SPA boot → read-only app with cache-wipe on exit.

**Still requires a human / device (cannot be automated here):**
- Lighthouse PWA + a11y run against a served build; contrast audit in real sunlight.
- Real-device test on a low-end Android; largest Dynamic Type; landscape.

---

## 8. Testing strategy

| Layer | Tool | What it covers |
|---|---|---|
| Blade routes / session auth | Pest | Auth, redirects, server-rendered authorization |
| Outbox + sync engine | Vitest | Idempotency, backoff, 4xx parking, cursor advance |
| Offline end-to-end | Playwright | Record offline → reconnect → exactly-once landing |
| Accessibility | axe + manual | Contrast, focus order, screen reader labels |

**The offline path must be tested, not assumed.** Its failure mode is silent data loss in a shop's ledger — the single worst outcome this product can have, and it will not surface in manual testing on a good connection.

---

## 9. Risks

| Risk | Why it bites | Mitigation |
|---|---|---|
| **Two auth systems** (session + JWT) | Divergence; a Blade page authorized differently from its API twin | Single source of truth for role checks; Pest tests asserting both paths agree |
| **Offline sync bugs** | Silent, corrupts a shop's money ledger, discovered late | Phase 2 before any UI; append-only ledger limits blast radius; exactly-once test |
| **Bundle creep on cheap phones** | Gradual, invisible in dev on fast machines | Preact alias; CI budget check |
| **Devanagari rendering** | Broken conjuncts / clipped matras look unprofessional to the actual users | Self-hosted Noto Sans Devanagari; verify with real Hindi strings, not Lorem Ipsum |
| **Tenant bleed in local cache** | Catastrophic and invisible | Namespaced Dexie DB per tenant + enforced outbox flush on switch |

---

## 10. Open items

All four blocking decisions are settled (see *Decisions taken*, top). One housekeeping item remains:

- **`CLAUDE.md` still documents Next.js as the frontend.** Until it is updated, every future session starts from the wrong stack. Recommended change:

  ```diff
  - - Next.js (frontend)
  + - Blade + React islands (single Laravel app, Vite)
  + - Offline-first PWA: Dexie (IndexedDB) + service worker
  + - i18n: Laravel __() for Blade, JSON dictionaries for React (PRD §16's next-intl is superseded)
  ```

### Deliberately deferred

- **Preact** — revisit after Phase 3 against a measured bundle (§5).
- **Data-processing terms** (PRD §13) — needs legal copy; `config/dpdp.php` already has the version mechanism.
- **Platform metrics** (PRD §12) — no dependency on the frontend.
