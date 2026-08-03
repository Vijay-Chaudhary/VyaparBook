# Order Workflow — Order → Accept → Pack → Deliver — Design

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

**Date:** 2026-07-26
**Status:** Draft (design); awaiting sign-off.
**Scope:** A salesman takes an **order** in the field; an owner or admin accepts
it (adjusting quantities or prices if needed) or rejects it; the salesman marks
it packed and then delivered. **Delivery creates the sale.** Nothing counts as
money owed until goods arrive.

---

## Background

There is no such thing as an order today. `sales` has no status column: a sale
row **is** a khata entry the moment it is written, and every figure in the app
is built on that — outstanding, cash flow, COGS, finished-goods stock, GST
invoices, and the overdue list that drives reminders.

Introducing a stage *before* "the customer owes me" collides with that
invariant, so the first decision is about money, not statuses.

## Decisions

1. **Delivery creates the sale; orders and sales stay separate tables.** Until
   an order is delivered nobody owes anything, which matches the trade: you owe
   when the goods reach you. Crucially, `sales` keeps meaning exactly what it
   means now, so **no existing money query changes** — outstanding, cash flow,
   COGS, finished goods, invoicing and reminders are untouched.

2. **Orders replace direct sales in the app.** Every sale now begins as an
   order. The server still accepts the `sale` outbox mutation and
   `POST /api/v1/sales`: removing them would break any phone that has not
   updated and the REST surface, for no gain. The flow changes because the UI
   changes, not because the server forgot how.

3. **Full delivery only — one order, one sale.** Delivering marks the whole
   order done. If less will go out, the owner adjusts the order at acceptance.
   Partial delivery can be added later without redesigning this.

4. **Status moves forward only, and never out of a terminal state.** Each
   status has a rank; the server refuses any transition that does not increase
   it. This is the rule already proven on `reminder_logs`
   (`queued → sent → delivered → read`), and it is what makes a replayed or
   out-of-order push from a phone that has been offline for days safe.

5. **Acceptance is the only online step**, which makes it the sync boundary:
   a salesman cannot pack until their phone has pulled the acceptance. That is
   honest — until then they genuinely do not know it was approved.

6. **Rejection beats a late delivery.** If the owner rejects while the salesman
   was offline and already delivered, the `deliver` push is refused, that one
   mutation parks with a reason, and the rest of the batch applies. Letting the
   field win would create a sale the owner explicitly declined.

7. **The owner may adjust quantities and prices at acceptance.**
   ~~**Known limitation, accepted deliberately:** the original order is not
   preserved, so if a shop was promised 10 packs at ₹90 and receives 8 at ₹95,
   nothing records that it changed. Recording both was considered and rejected
   as more machinery than the workflow currently earns.~~
   **Superseded (2026-07-29)** by
   `2026-07-29-order-acceptance-audit-trail-design.md`: `order_lines` now
   carries `ordered_qty`/`ordered_rate`, stamped at creation, and the change is
   derived rather than stored.

8. **Packing does not touch stock.** Finished goods are derived (production
   minus sales), so inventory moves exactly when the sale is created at
   delivery. Reserving at packing would mean a stored counter that can drift
   from the events — which this codebase avoids everywhere else.

## The state machine

```
              ┌──────────► rejected ─── terminal
              │            (owner/admin, online)
  pending ────┤
  (salesman,  │
   offline)   └──► accepted ──► packed ──► delivered ─── terminal
                  (owner/admin) (salesman) (salesman)     ↳ creates the sale
                     online      offline     offline

  cancelled ─── terminal, from pending | accepted | packed
              (owner/admin any time; salesman in the field)
```

Ranks: `pending` 0, `accepted` 1, `packed` 2, `delivered` 3. `rejected` and
`cancelled` are terminal and may be entered from any non-terminal state; no
transition may leave a terminal state.

**Permissions mirror existing policy rather than inventing roles:** taking an
order is allowed for whoever may record a sale today (owner, admin, salesman —
not accountant); accepting and rejecting are owner/admin, like voiding a sale.
Cancellation is available to owner/admin for any non-terminal order, and to a
salesman ~~**for orders they created**~~ in any non-terminal state — including
one still `pending`, since a shop that changes its mind an hour later should not
need the owner to intervene. A shop refusing goods at the door is the case that
justifies cancelling a `packed` order.

**Superseded (2026-07-29):** the creator restriction was enforced solely by pull
visibility and never by a check. Now that anyone may deliver, anyone may cancel
— the person at the door refusing the goods is the one who has to record it. See
`2026-07-29-order-delivery-by-anyone-design.md` Decision 4.

`status_note` carries the reason for **both** rejection and cancellation, and is
optional in each case: an unexplained rejection is unhelpful but not invalid.

## Schema

| Table | Columns |
|---|---|
| `orders` | id, business_id, `uuid` (client idempotency key), customer_id, order_date, `status`, total, created_by, accepted_by, accepted_at, `status_note` (nullable — so a rejection can say why), `sale_id` (nullable — what it became), version, sync_seq, timestamps. Unique `(business_id, uuid)`; indexes on `(business_id, sync_seq)` and `(business_id, status)`. RLS + app scope. |
| `order_lines` | id, business_id, order_id, product_pack_id, qty, rate, line_total, version, sync_seq. **No `list_rate`** — that is authored server-side when the sale is created, so an order has no business carrying it. RLS + app scope. |

Both carry `sync_seq` and ride the existing delta pull.

## Architecture

| Unit | Responsibility |
|---|---|
| `App\Orders\OrderStatus` | Pure: ranks, terminal set, `canTransition(from, to)`. The rule everything else leans on, testable alone. |
| `App\Services\OrderWriter` | Idempotent create/pack/deliver/cancel by `(business_id, uuid)`, mirroring `LedgerWriter`'s shape. Delivery calls `LedgerWriter::createSale`. |
| `Web\OrderController` | Owner/admin Blade: pending list, accept (editable qty/rate), reject with a note, read-only view of the rest. |
| `Api\V1\SyncController` | Four new outbox mutation types: `order`, `order_pack`, `order_deliver`, `order_cancel`. Accept/reject are **not** outbox types. |
| `resources/js/screens/RecordOrder.jsx` | Replaces `RecordSale` — same line editor, negotiated rate and floor block, writes an order. |
| `resources/js/screens/Orders.jsx` | The salesman's orders by status, with only the actions each state allows. |
| `resources/js/offline/orders.js` | Pure: which actions a status permits; grouping for the list. |

## Delivery creates the sale

`order_deliver` calls `LedgerWriter::createSale` with the order's lines — the
same path every sale already uses, so the cost floor is re-enforced at delivery
(rates may have been edited at acceptance), `list_rate` is server-authored as
always, and no money code learns about orders.

**Idempotency comes free:** the created sale reuses the **order's uuid**.
`createSale` already returns the existing row for a replayed uuid, so a
`deliver` mutation arriving twice cannot produce two sales or double a khata.

Two details that must not be left to interpretation, because both affect what
the books say:

- **The sale's `sale_date` is the delivery date, not the order date.** The sale
  records a money event, and the money event is the goods arriving. An order
  taken on the 1st and delivered on the 4th belongs to the 4th — otherwise the
  daily and monthly figures report revenue on a day nothing was handed over.
- **The sale's `created_by` is whoever delivered it**, not whoever took the
  order. `created_by` on a ledger row means "who recorded this money", and the
  delivery is the act being recorded. The order keeps its own `created_by` for
  who promised it.

## Sync visibility

~~A salesman pulls the orders they created; owner and admin pull all — the same
shape as beats, where a salesman gets only their own route. **This assumes the
person who took the order delivers it**, which holds for a one-van operation but
not for a shop that separates selling from delivery.~~

**Superseded (2026-07-29)** by `2026-07-29-order-delivery-by-anyone-design.md`:
every user in the tenant now pulls every order, as they already pull every sale.
The server never checked who took an order, so this was visibility only.

## Error handling / edge cases

| Case | Behaviour |
|---|---|
| Deliver pushed for a rejected or cancelled order | Refused (terminal); that mutation parks with a reason, batch continues |
| Deliver replayed | Same sale returned via the shared uuid — never two |
| Owner edits a rate below the cost floor at acceptance | Refused by the same `PriceFloor` rule as everywhere else |
| Pack pushed before acceptance has synced | Refused server-side; the client also hides the action |
| Product pack archived between order and delivery | `createSale` 404s; the mutation parks |
| Cancel after delivery | Refused — delivered is terminal |
| Order with no lines, or a zero quantity | Rejected at validation, as a sale is today |
| Order for an archived customer | Refused; the customer is invisible under RLS |

## Testing

- **Unit (PHP)** — `OrderStatusTest`: ranks, every legal transition, every
  illegal one, and that no transition leaves a terminal state.
- **Feature (PHP)** — accept and reject (including a below-floor edit refused);
  each push mutation; **delivery creates exactly one sale with the accepted
  rates**; a replayed delivery creates none; delivery against a rejected order
  parks without killing its batch.
- **Feature (PHP)** — sync pull: a salesman sees only their own orders; no
  cross-tenant leak.
- **JS unit** — `orders.js`: which actions each status permits, list grouping.
- **Regression** — khata, outstanding and every dashboard figure are unchanged
  by taking, accepting or packing an order; they move only on delivery. Existing
  sale tests continue to pass untouched.

## A note on size

This is one workflow but a large one: two tables, four mutation types, a pure
status unit, a writer, a Blade surface and two phone screens. It is kept as a
single spec because splitting it leaves a useless half — orders that can never
become sales. The implementation plan should build it **inside-out**: the status
rules first, then the writer, then sync, then the Blade accept screen, then the
phone screens, with the regression assertion (khata does not move before
delivery) landing as early as the writer exists.

## Out of scope

Partial delivery; stock reservation at packing; ~~recording what changed at
acceptance (Decision 7)~~ (shipped 2026-07-29, see Decision 7); delivery by
someone other than the order-taker;
customer-facing order notifications; and any change to how sales, khata, COGS,
invoicing or reminders work.

## Traceability

- PRD §7 sale entry → the sale is now the *result* of a delivered order.
- PRD §9 always-recomputable → orders add no stored figure that cannot be
  recomputed; the sale remains the single money event.
- Multi-tenant isolation → RLS **and** an explicit `business_id` scope on both
  new tables, tested.
