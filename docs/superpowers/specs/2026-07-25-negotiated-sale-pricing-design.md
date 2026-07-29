# Negotiated Sale Pricing & Ledger Line Items — Design

**Date:** 2026-07-25
**Status:** Draft (design); awaiting sign-off.
**Scope:** Let a salesman change a sale line's price in the field, bounded by a
cost floor, and show what was sold — with the price actually charged — in the
customer's khata ledger.

---

## Background

Price is negotiated per order in this trade, but the app has no way to express
that. Today the client cannot influence price at all: `LedgerWriter::rulesForSale`
accepts only `product_pack_id` and `qty` per line, and the rate is set by
`KhataService::snapshotRate($pack)`, whose docblock calls it *"the one home for
that decision."* Whatever the salesman agreed, the books record the list price.

The **history** half of the request is already satisfied structurally:
`sale_lines.rate` is frozen per line at write time, never mutated, and a void is
a negated row rather than an edit. Once the rate can vary, "what did we charge
this customer in March" is answerable with no schema change. The gap is only
that nothing can vary it.

The ledger shows `Sale · 25 Jul · +₹525` and nothing about what was in it, even
though `sale_lines` already sync to the phone.

## Decisions

1. **The rate becomes client-supplied; the list rate does not.** `rate` arrives
   from the phone (validated and floored). `list_rate` is always computed
   server-side from `snapshotRate($pack)` and never accepted from the client. If
   the client could send both, it could claim it sold at list while charging
   less, and the discount record would be fiction.

2. ~~**A cost floor, enforced on both sides.** The phone blocks a below-floor
   line at entry, while the salesman can still renegotiate face to face; the
   server re-validates independently, because a rule enforced only on a client
   is not enforced. Rejected pushes park in the outbox's existing state with the
   server's reason — no new failure mode is invented.~~

   **Superseded (2026-07-29)** by `2026-07-29-owner-control-and-pricing-plan.md`
   Phase 1. This assumed below-cost never legitimately happens. It does — this
   shop sells some packs at or under cost deliberately, and against its true
   costs (₹93/₹130/₹117 per kg) **11 of 21 packs** were below their own default
   selling price, so enforcing meant half the catalog could not be sold at all.
   The floor is still computed and shown, and the phone now asks for one
   explicit confirmation instead of refusing. The cost basis is snapshot to
   `sale_lines.cost_at_sale` so below-cost selling stays reportable after costs
   move.

3. **The floor derives from data already cached, so no new configuration:**

   ```
   floor(pack) = pack.default_cost_price                          — if set
               ?? product.base_cost_per_kg × pack_size.weight_kg  — derived
               ?? none                                            — unbounded
   ```

   Every input is in the catalog payload the phone already holds, so both
   runtimes reach the same answer from the same data. Where a pack has neither a
   cost nor a cost-per-kg, the line is **unbounded** — stated plainly rather
   than silently permissive.

   Three details that must not be left to interpretation, because the two
   runtimes have to agree to the paisa:

   - **Equal to the floor is allowed.** Only `rate < floor` is rejected. Selling
     at cost is a real decision a shop makes, not an error.
   - **The derived floor is rounded UP to the paisa.** `base_cost_per_kg ×
     weight_kg` is `decimal(10,2) × decimal(8,3)`, so it can land on a fraction;
     rounding up guarantees the floor never sits below true cost.
   - **The floor is checked on the rate alone, independent of the quantity's
     sign.** A return is a negative qty at a positive rate, so it is bounded by
     the same rule as the sale it reverses.

4. **`sale_lines.list_rate` is nullable and never backfilled.** It means "the
   default on the day of sale". Historical rows genuinely have no such value, and
   inventing one from today's default would make future discount analysis wrong
   while looking authoritative. Null means unknown.

5. **Ledger items are shown inline, not behind a tap**, and read from two
   sources: a synced sale's lines from cached `sale_lines`, a queued sale's from
   its outbox payload. The ledger already lists pending sales; they would look
   broken with no items beneath them.

6. **No discount badge.** `list_rate` makes discount reporting possible later,
   but a badge is a separate feature and would be guessing at what it should say.

## The duplication this costs

Approach A requires the floor rule to exist twice — `App\Pricing\PriceFloor`
(PHP) and `resources/js/offline/pricing.js` (JS). Duplicated logic drifts, and
naming that is better than pretending otherwise. Mitigation is deliberate: both
are pure functions with no I/O, and both are tested against **the same table of
cases** (cost set, cost null with weight, both missing, zero cost, fractional
weight). A case added to one table must be added to the other; the two test
files are meant to read as one contract.

## Schema

| Change | Notes |
|---|---|
| `sale_lines.list_rate` decimal(10,2) **nullable** | The pack default on the day of sale. Server-authored. Null for pre-existing rows. |

Nothing else changes. `sale_lines` already streams in the delta pull and Dexie
stores whole rows, so the new field reaches the phone with no client schema bump.

## Components

| Unit | Responsibility |
|---|---|
| `App\Pricing\PriceFloor` | Pure. `for(ProductPack): ?string` — the floor, or null when unbounded. |
| `resources/js/offline/pricing.js` | Pure. The same rule for the phone, plus a `belowFloor` check for the form. |
| `LedgerWriter::createSale` | Accepts an optional per-line rate, computes `list_rate` itself, enforces the floor, stores both. |
| `LedgerWriter` void path | Copies `rate` and `list_rate` **unchanged**, negating only qty and line_total — exactly as it already treats `rate`. A reversal must mirror the original price, not re-derive one that may have moved. |
| `RecordSale` (Forms.jsx) | Rate field per line, prefilled and re-filled on product change; per-line subtotal; inline floor error; total from line rates. |
| `offline/khata.js` `ledgerFor` | Attaches line items to each sale entry, from `sale_lines` or the outbox payload. |
| `CustomerLedger.jsx` | Renders those items beneath each sale. |

## Data flow

```
salesman edits rate
  → pricing.js floor check (blocks at entry, names the limit)
  → outbox payload now carries rate per line
  → push → rulesForSale validates rate (numeric, >= 0, 2dp, optional)
  → LedgerWriter: list_rate = snapshotRate(pack)   [server-authored]
                  rate      = client rate ?? list_rate
                  floor check → violation parks this item, batch continues
                  line_total = rate × qty
  → sale_lines rows carry both rates, frozen
  → ledger reads them back; reports read sale.total as before
```

## Error handling / edge cases

| Case | Behaviour |
|---|---|
| Rate omitted (REST caller, older client) | Server uses the default; behaviour unchanged. Backwards compatible. |
| Rate above list | Allowed — negotiating upward is legitimate. |
| Rate 0 | Allowed only where the floor is none or zero; otherwise blocked like any below-cost price. |
| Negative rate | Rejected. A return is a negative **qty**, never a negative price. |
| No cost and no cost-per-kg | Floor is none; any non-negative rate accepted. |
| Cost changed between cache and push | Server re-validates against current cost; a violation parks with its reason. |
| Product changed on a line after typing a rate | Rate re-fills from the new product's default — a rate typed for the old product is meaningless. |
| Sale with no cached lines yet (queued) | Items render from the outbox payload. |
| Reversal entry | Shows its negated lines, so a void is visibly real. |

## What does not change

Revenue derives from `sale.total`, GST extracts tax from `line_total`, finished
goods use `qty × weight`, COGS is cost-side. A negotiated price flows into all of
them correctly with no report touched.

## Testing

- **PHP unit** — `PriceFloorTest`: the shared case table, including a rate
  exactly equal to the floor (allowed) and a derived floor that rounds up.
- **PHP unit** — `LedgerWriter`: rate honoured; rate omitted falls back to
  default; below-floor rejected naming the product; a client-sent `list_rate` is
  ignored in favour of the server's; `line_total = rate × qty`; void copies both
  rates unchanged while negating qty and line_total.
- **PHP feature** — sync push: a below-floor item parks with a reason while the
  rest of the batch applies.
- **JS unit** — `pricing.test.js`: the same case table as PHP.
- **JS unit** — `khata.test.js`: line items attached for a synced sale and for a
  queued one.
- No component tests: the repo has no testing-library/jsdom, so display logic
  stays in pure modules.

## Out of scope

Discount reporting and any badge comparing rate to list; a percentage-band floor;
per-pack minimum price columns; changes to GST, COGS or finished-goods logic.

## Traceability

- PRD §7 sale entry → the rate becomes negotiable within a floor.
- PRD §9 always-recomputable → `rate`/`list_rate` are frozen facts of the sale,
  like every other ledger row; nothing is derived from them that cannot be
  recomputed.
- Multi-tenant isolation → unchanged; all writes stay inside `LedgerWriter` under
  the tenant pin.
