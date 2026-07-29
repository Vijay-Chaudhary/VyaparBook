# Order Acceptance Audit Trail — Design

**Date:** 2026-07-29
**Status:** Draft (design); awaiting sign-off.
**Scope:** Preserve what a salesman originally ordered, so that an owner's edit
at acceptance is visible to everyone afterwards. Closes the known limitation
recorded as Decision 7 of the order workflow spec
(`2026-07-26-order-workflow-design.md`) and in backlog item F-13.

---

## Background

`OrderController::accept()` writes the owner's edited `qty` and `rate` **over**
the salesman's numbers on `order_lines`, then recomputes `total`. The order
workflow spec named this and shipped it anyway:

> **Known limitation, accepted deliberately:** the original order is not
> preserved, so if a shop was promised 10 packs at ₹90 and receives 8 at ₹95,
> nothing records that it changed.

The cost lands on the person least able to absorb it. A salesman promised a
shopkeeper ten packs at ₹90; the owner approves eight at ₹95; the phone syncs;
the salesman now sees eight at ₹95 with no indication anything moved, and finds
out at the door. The order is also the only record of the promise, so once it is
overwritten there is nothing to settle the argument with.

`accepted_by` and `accepted_at` already record **who** decided and **when**.
What is missing is **what changed**.

## Decisions

1. **Capture the original at creation, not the delta at acceptance.**
   `order_lines` gains `ordered_qty` and `ordered_rate`, stamped once by
   `OrderWriter::createOrder` and never written again. "Was this edited?" is
   then **derived** by comparing them with the live `qty`/`rate` — no stored
   flag, nothing that can drift from the rows it summarises (PRD §9).

   The alternative — writing the old value only when acceptance changes it —
   stores less but makes `null` mean *either* "unchanged" *or* "predates this
   feature". An audit trail whose silence is ambiguous cannot be used to
   confirm that nothing changed, which is half of what it is for.

2. **Null means unknown, and is never invented.** Rows written before this
   ships keep `null` and are **not** backfilled from their current values —
   those values may already be an owner's edit, and copying them would
   manufacture a record saying "nothing changed" that is authoritative-looking
   and possibly false. This is the rule `sale_lines.list_rate` already follows
   (F-12).

   **The one exception is provable, so it is taken:** lines belonging to orders
   still in `pending` have by definition not been through acceptance, so their
   current `qty`/`rate` *are* the original. The migration backfills exactly
   those. This is not inventing history; it is reading it.

3. **Server-authored, never client-supplied.** `ordered_qty`/`ordered_rate` stay
   out of `$fillable` and are stamped like `line_total`, so a phone cannot claim
   it ordered something it did not. Same reasoning as `list_rate` on sales: the
   value exists to hold a party to what they did, so that party must not author
   it.

4. **The comparison is one pure unit used by both runtimes.**
   `App\Orders\OrderAdjustment` answers "did this line change, and what was the
   original line/order total" for Blade; `describeLines` gains the same
   derivation for the phone. Both read the same two columns and apply the same
   rule (a change is a difference in qty **or** rate), mirroring how `PriceFloor`
   is deliberately duplicated across PHP and JS against one shared case table.

5. **Shown wherever the accepted numbers are shown.** Three surfaces, all
   existing:
   - the salesman's `Orders.jsx` list — the highest-value one, because it is
     read standing in front of the customer;
   - the owner's "Recently decided" table on `/orders`, which is where a dispute
     is actually looked up;
   - nowhere else. The customer ledger deliberately keeps showing the sale as
     sold — an order's negotiation history is not a khata entry.

6. **Adding or removing lines at acceptance stays unsupported**, exactly as
   today: the accept form iterates the lines that exist and `qty` is
   `not_in:0`. So the change set this must describe is bounded to qty and rate
   on a fixed set of lines, which is what keeps the design this small.

## Schema

| Table | Change |
|---|---|
| `order_lines` | `ordered_qty` (integer, **nullable**), `ordered_rate` (decimal 10,2, **nullable**). Written once at creation; never updated. Backfilled from `qty`/`rate` for lines of `pending` orders only. |

No new table, no index (these are read with the line, never searched on), no RLS
policy change — both columns live on a table that is already isolated.

**The phone needs no Dexie migration.** `order_lines` is stored by Dexie as
whole objects (`'id, order_id, sync_seq'` declares indexes only), and the sync
pull serialises models wholesale, so the two columns arrive on the device
without a version bump or a payload change.

## Architecture

| Unit | Responsibility |
|---|---|
| `App\Orders\OrderAdjustment` | Pure: `changed(line)`, `originalTotal(lines)`. DB-free and testable alone, like `OrderStatus`. |
| `App\Services\OrderWriter` | Stamps `ordered_qty`/`ordered_rate` at create. Unchanged otherwise. |
| `Web\OrderController` | Unchanged logic — it already overwrites only `qty`/`rate`, which is now safe because the originals live elsewhere. |
| `resources/js/offline/lineItems.js` | `describeLines` emits `originalQty`/`originalRatePaise` **only when known and different**. |
| `resources/js/screens/Orders.jsx` | Renders "was 10 × ₹90" under an edited line. |
| `resources/views/orders/index.blade.php` | Same, in the decided-orders table. |

## What the reader sees

An unedited line looks exactly as it does today — no annotation, no empty
column, no "(unchanged)". The trail appears **only** when something positively
changed, so it never adds noise to the common case, and a line whose original is
unknown is indistinguishable from an unchanged one **by design**: the UI never
claims more than the data supports.

## Error handling / edge cases

| Case | Behaviour |
|---|---|
| Line predating this feature (`ordered_*` null) | No annotation; reads as it does today |
| Order accepted with no edits | `ordered_* == qty`/`rate`; no annotation |
| Only qty edited, or only rate | Annotated; the unchanged half is still shown so the pair reads as a whole ("was 10 × ₹90") |
| Acceptance refused by the cost floor | Nothing written at all, originals untouched — the existing refusal is whole-order |
| Delivery | Copies the **accepted** qty/rate into the sale, unchanged from today; the sale never carries `ordered_*` |
| Rejected or cancelled order | Keeps its originals; there is nothing to compare against, so nothing is shown |

## Testing

- **Unit (PHP)** — `OrderAdjustment`: changed on qty, on rate, on both; not
  changed when equal; **not changed when either original is null**; original
  total across a mixed set.
- **Feature (PHP)** — `createOrder` stamps the originals; a phone payload
  claiming `ordered_qty` cannot set it; acceptance that edits leaves the
  originals intact; acceptance that edits nothing leaves them equal.
- **Migration (PHP)** — a `pending` order's lines are backfilled; an `accepted`
  order's lines are left null.
- **JS unit** — `describeLines` emits the original only when known and
  different; sale lines (which have no such columns) are unaffected.
- **Regression** — delivery still creates a sale at the accepted rates; khata,
  outstanding and every dashboard figure unchanged.

## Out of scope

Adding or removing lines at acceptance; a full per-field revision history with
multiple edits (acceptance happens exactly once, so there is only ever one
before-and-after); notifying the salesman that their order was edited;
surfacing the negotiation in the customer ledger or on the GST invoice.

## Traceability

- Order workflow spec Decision 7 → closed; that spec's "Out of scope" line
  "recording what changed at acceptance" is superseded by this document.
- Backlog F-13 known limitation → closed.
- PRD §9 always-recomputable → the change set is derived, never stored.
- Multi-tenant isolation → no new table; both columns inherit `order_lines`'
  existing RLS policy and app-level scope.
