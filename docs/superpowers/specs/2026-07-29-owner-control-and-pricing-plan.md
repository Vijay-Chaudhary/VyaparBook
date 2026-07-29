# Owner Control & Pricing Configuration — Plan

**Date:** 2026-07-29
**Status:** Phases 1, 2 and **Phase 3 part 1** shipped 2026-07-29. The
reversal-vs-delete question is settled: **correct by reversal**. Phase 3 part 2
(stock and production reversal) remains, and needs its own migration plus new
offline outbox mutation types.
**Scope:** (1) make the cost floor advisory, (2) give the owner a pricing
configuration screen, (3) let the owner correct orders, payments, customers,
stock and production.

---

## What prompted this

The shop's real costs are **₹93/kg Senvda, ₹130/kg Mix Sev, ₹117/kg Sev**, and
selling price is **negotiated per customer every time**. Two facts follow, and
they drive the whole plan:

1. Against those costs, **11 of the 21 packs in the catalog sell below cost** at
   their own default price — and the owner confirms that is real, not a data
   error. Some packs genuinely go out at or below cost.
2. There is **nowhere in the product to change a cost**. Catalog CRUD exists
   only on the JSON API; no Blade screen reaches it.

Today `PriceFloor` **refuses** a below-cost line — in `LedgerWriter`,
`OrderWriter`, the accept screen and the phone. So entering the true costs would
block roughly half the catalog from being sold at all.

| Product | True cost/kg | Catalog sells | Packs refused |
|---|---|---|---|
| Senvda | ₹93 | ₹90–100/kg | 2 of 8 |
| Mix Sev | ₹130 | ₹120–133/kg | 4 of 7 |
| Sev | ₹117 | ₹108–118/kg | 5 of 6 |

Worst cases: **Sev 1kg** costs ₹117 and sells ₹110 (−₹7); **Mix Sev 1kg** costs
₹130 and sells ₹120 (−₹10).

**Ordering matters:** Phase 1 must ship before anyone enters the real costs, or
the shop cannot trade.

---

## Phase 1 — the cost floor becomes advisory — ✅ SHIPPED 2026-07-29

**What actually shipped**, against the proposal below:

- [x] Throw removed from `LedgerWriter::createSale`, `OrderWriter::createOrder`
      and `Web\OrderController::accept`.
- [x] Phone shows the floor, marks the line, and no longer locks submit.
- [x] Below cost requires one explicit tick, reset by any later edit — so what
      is confirmed is always the final numbers.
- [x] Recorded: `sale_lines.cost_at_sale`, snapshot at sale, server-authored,
      nullable and never backfilled.
- [x] Accept screen prints "Under cost — ₹x" beside an under-cost rate. Built
      first as a permanent Cost column; **changed on review** to warn only when
      a rate is actually below cost, so the common line stays uncluttered.
- [x] Six tests flipped one at a time. Two of them (`createSale` atomicity, and
      sync push isolating one bad mutation) were protecting a guarantee that had
      nothing to do with pricing, so they were **retargeted at an unknown pack**
      rather than deleted.
- [ ] **Not built:** the below-cost dashboard figure. `cost_at_sale` now makes
      it derivable, but no screen reports it yet — worth doing once real
      below-cost sales exist to look at.

`PriceFloor` itself was not modified: it only ever computed the number.

### Original proposal


**The problem.** `PriceFloor` was designed as a hard bound (F-12: "the phone can
block a below-cost price while the customer is still there"). That assumed
below-cost never legitimately happens. It does.

**The change.** The floor keeps being *computed* and *shown*; it stops being
*enforced*. Below-cost becomes a visible, deliberate act rather than a refusal.

- Remove the throw from `LedgerWriter::createSale`, `OrderWriter::createOrder`
  and `Web\OrderController::accept`.
- The phone keeps showing the floor and marks a below-cost line in the danger
  colour, but the submit button no longer locks.
- **Below cost requires one explicit confirmation** ("Sell below cost?") rather
  than passing silently. This is the part worth arguing about and I recommend
  keeping it: the floor's *other* job was catching a mis-key — ₹9 typed for ₹90
  is below almost any floor — and a silent pass loses that for good. One tap
  keeps the typo catch while permitting the real case.
- Record it: a line sold below its floor sets a flag (or is derivable, since
  `list_rate` and cost are both known) so the dashboard can answer "what did we
  sell under cost this month?" — which is the question the owner will have the
  moment this is allowed.

**Supersedes** F-12's hard-block decision and the "refuses" row in the negotiated
pricing spec.

**Touches:** `App\Pricing\PriceFloor` (unchanged — it only computes),
`LedgerWriter`, `OrderWriter`, `OrderController`, `offline/pricing.js`,
`Forms.jsx`, and the ~8 tests that currently assert refusal.

**Risk:** low mechanically, high in meaning. Every existing test that asserts a
below-floor refusal has to flip, and those tests are the record of the old
decision — each flip should be deliberate, not bulk-edited.

---

## Phase 2 — pricing configuration screen — ✅ SHIPPED 2026-07-29

**What actually shipped:**

- [x] `/pricing`, owner-only Blade, grouped by product: per-kg cost per product,
      then pack cost and suggested price per pack.
- [x] Margin in money **and** percent, red on a loss.
- [x] The precedence trap made visible: each pack row says whether its cost
      *overrides* the per-kg figure or is *derived from* it.
- [x] **"Recost every pack from ₹x per kg"** per product — the action that
      defeats the trap. Rounds **UP** to the paisa, matching what `PriceFloor`
      does when it derives a floor; `CatalogService::suggestedCostPrice`
      truncates, so it is deliberately not reused here (it would set every
      stored floor a paisa *below* true cost).
- [x] Blank cost box stores **NULL, not zero** — zero is a real, different
      answer (a free issue) that `PriceFloor` honours.
- [x] Selling price labelled a **starting suggestion**, since every sale here is
      negotiated.
- [x] Linked from the dashboard, not URL-only.
- [x] New pure `App\Pricing\Margin` (DB-free, like `PriceFloor`), 10 unit tests.
- **Bug found and fixed on the way:** the controller eager-loaded only
      `packSize`, but `PriceFloor`'s docblock requires `product` too. Packs with
      a stored cost return early and never touch it, so this only broke on
      derived-cost packs — invisible on the real catalog, where all 21 have
      stored costs. Caught by a test, not by looking.
- [ ] **Not built:** dated cost history. Costs overwrite, per the recommendation
      below — last month's COGS shifts if you edit a cost today.

**Decision taken:** owner-only, matching GST/reminders/beats/purchases. Order
acceptance remains the one screen admitting admins, because that is a daily
operational job; setting cost is not.

### Original proposal


**The problem.** `default_sell_price`, `default_cost_price` (per pack) and
`base_cost_per_kg` (per product) are reachable only through
`/api/v1/product-packs` etc. There is no UI, so a cost change needs a developer.

**A trap to fix while here:** `PriceFloor` reads `default_cost_price` **first**
and only falls back to `base_cost_per_kg × weight`. All 21 packs currently have
a per-pack cost set, so **setting the per-product per-kg cost alone changes
nothing.** A screen that lets an owner type ₹93 and see no effect is worse than
no screen. The screen must make the precedence visible and offer "recost every
pack from the per-kg figure" as one action.

**Proposed screen** — `/pricing`, owner-only, one row per pack:

| Product | Pack | Weight | Cost/kg | Pack cost | Default sell | Margin | |
|---|---|---|---|---|---|---|---|
| Senvda | 400g | 0.400 | 93.00 | 37.20 | 36.00 | **−1.20** | edit |

- Margin shown in money **and** %, in the danger colour when negative — so the
  11 below-cost packs are visible at a glance instead of being discovered at the
  till.
- Editing `base_cost_per_kg` offers to recompute every pack's cost for that
  product; each pack can still be overridden individually (a 250g pouch costs
  more per kg to pack than a 1kg bag, which is exactly why the override exists).
- **Selling price is labelled a starting suggestion, not a price.** Since every
  sale is negotiated, presenting it as "the price" misrepresents the business.
- No new tables. Reuses `ProductPackController`'s validation via a Blade
  controller, or calls the same service.

**Open question for you:** should changing a cost be **dated** (cost history) or
just overwritten? Overwriting is far simpler but makes last month's COGS shift
retroactively when you update a cost today. Dated costs are correct but a real
piece of machinery. My recommendation: overwrite now, note it, and add history
only if the P&L drift actually bites — `production_batches` already capture
actual cost per batch, which is the more truthful COGS path (Phase 2b).

---

## Phase 3 — owner corrections — PART 1 SHIPPED 2026-07-29

**Decision taken: correct by reversal, not deletion.**

**Discovery that reshaped this phase:** sale void and payment reversal were
**already fully built** on the JSON API (`POST /api/v1/sales/{id}/void`,
`POST /api/v1/payments/{id}/reverse`), with `reverses_id` on both tables and
guards against reversing a reversal or double-reversing. Only the UI was
missing — the same situation pricing was in.

**Shipped (part 1 — money and orders):**

- [x] `App\Ledger\LedgerReverser` — the reversal logic **extracted** from the two
      API controllers so the new Blade caller shares it. Duplicating the guards
      across callers is exactly how they drift.
- [x] `App\Ledger\ReversalNotAllowed` carries a machine-readable reason, so the
      API keeps its 422-vs-409 distinction (its tests pin those) while Blade
      shows a sentence.
- [x] Void and Reverse on the owner's customer ledger, which was read-only.
      Already-corrected rows are marked instead of offering the action again.
- [x] The reversal mirrors `cost_at_sale` as well as `list_rate` — a void must
      cancel the sale that happened, not the one today's cost would describe.
- [x] Owner order cancel on `/orders`. A **real** cancel, not a reversal: an
      order before delivery is not money, so there is nothing to mirror.
- **Bug found by verifying in the browser, not by the tests:** the ledger view
      rendered neither `session('status')` nor `session('error')`, so every
      refusal was invisible and the button looked broken. The original tests
      asserted `assertSessionHas` and passed throughout. Two tests now assert
      the message is **on the page**.

**Still to do (part 2 — stock and production):** `reverses_id` on
`stock_movements` and `production_batches` does not exist, and these are
offline-synced, so reversal needs a migration **and** new outbox mutation types.
Deliberately held back rather than buried in a UI change.

### Original proposal


This is the part I'd push back on, once, and then build whichever way you say.

**The finding.** The entities split cleanly in two, and the codebase already
treats them differently:

- **Masters** — customer, product, pack size, product pack, supplier, beat, raw
  material, expense, purchase. All ten carry `archived_at`. Editing and
  archiving these is safe and mostly already exists (customers got full CRUD in
  #27; expenses and purchases have delete).
- **Ledger events** — sale, sale line, payment, stock movement, production
  batch, material consumption, order. **None of these has any archive or delete
  column, and that is deliberate.** Every figure in the app derives from them:
  outstanding, cash flow, COGS, finished-goods stock, the overdue list that
  drives reminders, and GST invoices — which are immutable filed documents by
  law.

**What "delete a payment" would actually do.** Remove the row and a customer's
outstanding silently changes, a reminder may fire for money they already paid, a
cash-flow month that was reported changes retroactively, and if a GST invoice was
issued against the related sale, a filed document no longer matches the books.

**The recommendation.** Correct by **reversal**, not deletion — and the codebase
already has the idiom, so this is using the design rather than fighting it:

- Returns and voids are **already** negative-qty sale lines that self-net
  (F-09), and payment reversals already self-net in cash flow (F-03).
- So: an owner "Void" action writes a **reversing entry** dated today, links it
  to the original, and both stay visible. The ledger reads "sale ₹500, voided
  ₹500" instead of a gap where a sale used to be. Nothing recomputes wrongly,
  because nothing was removed.
- **Where a true edit is safe, allow a true edit.** An order before delivery is
  not money — editing or cancelling it is already fine and already exists. A
  customer's name, phone or address is a master field, not a ledger fact.
- **Refuse two things outright**, with the reason on screen: voiding a sale that
  has a GST invoice (issue a credit note instead — not yet built), and editing
  anything that would rewrite a filed tax period.

**If you want literal hard delete anyway**, say so and I will build it — but it
should be superadmin-only, audited via `PlatformAudit`, and blocked once an
invoice exists. It is the right tool for "this row is test rubbish", and the
wrong tool for "the customer returned the goods".

**Proposed order of work within Phase 3**, smallest useful first:

1. **Orders** — full owner edit/cancel before delivery. Cheapest: not money yet,
   and the accept screen is already 80% of it.
2. **Payments** — reverse a payment (creates the reversing entry), edit the note
   and date only.
3. **Sales** — void via reversing lines; the return path already exists in data
   but has no owner UI.
4. **Stock & production** — reverse a movement, correct a batch's output. These
   feed finished-goods and COGS, so they follow the same reversal rule.
5. **Masters** — fill the gaps: products, packs and pack sizes have no owner UI
   at all (Phase 2 delivers this), suppliers have no edit.

---

## Sizing, honestly

| Phase | Size | Blocking? |
|---|---|---|
| 1 — advisory floor | small (~1 day) | **Yes — must precede real costs** |
| 2 — pricing screen | medium (~2 days) | No |
| 3.1 — order corrections | small | No |
| 3.2–3.4 — reversals | medium each | No |
| 3.5 — master CRUD gaps | medium, mostly covered by Phase 2 | No |

Phases 1 and 2 together are the thing you actually asked for and unblock real
trading. Phase 3 is worth splitting into its own spec once the reversal-vs-delete
question is settled, because that answer changes every screen in it.

## Decisions I need from you

1. **Below-cost confirmation** — one explicit tap, or fully silent? (I recommend
   the tap.)
2. **Cost history** — overwrite, or dated costs? (I recommend overwrite for now.)
3. **Correct by reversal, or literal delete?** (I recommend reversal, with hard
   delete reserved for superadmin cleanup.)
4. Does anyone besides the owner get these powers — admin too, or owner alone?
