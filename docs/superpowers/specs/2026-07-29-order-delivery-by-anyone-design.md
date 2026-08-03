# Orders Visible to the Whole Shop — Design

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

**Date:** 2026-07-29
**Status:** Draft (design); awaiting sign-off.
**Scope:** Let anyone in the shop deliver any order, by removing the per-creator
filter on the order delta pull. Closes the second and last known limitation of
the order workflow (`2026-07-26-order-workflow-design.md` §Sync visibility) and
of backlog item F-13.

---

## Background

The order workflow shipped with this caveat:

> A salesman pulls the orders they created; owner and admin pull all. **This
> assumes the person who took the order delivers it**, which holds for a
> one-van operation but not for a shop that separates selling from delivery.

The assumption breaks in the ordinary case: a salesman takes an order on
Monday's round and is off on Thursday, or the packing boy runs the van. Nobody
else's phone has ever heard of the order, so nobody else can mark it delivered
— and delivery is what creates the sale, so the money never lands either.

## The important finding: nothing is being unlocked

**The server already lets anyone deliver.** `SyncController::roleAllows()`
checks the caller's *role* only, and `OrderWriter::deliver()` looks the order up
by uuid under RLS. There is no creator check on `deliver`, `pack` or `cancel`
anywhere in the codebase. A second salesman who somehow knew the uuid could
already deliver today.

So this change weakens no authorization. It is **purely** a visibility fix: the
orders were being withheld from the device, and that was the only thing standing
in the way.

It also removes an inconsistency rather than creating an exposure. `sales`,
`sale_lines`, `customers` and `payments` already stream **wholesale** to every
user in the tenant — only stock (owner/admin) and beats (per-salesman) are
filtered. A salesman can therefore already see every *sale* in the shop, which
is the money; they were being denied the *order*, which is only the working
state that precedes it. Delivering an order turns it into a sale they can
already read in full.

## Decisions

1. **Every user in the tenant pulls every order**, exactly as they already pull
   every sale. No role gate: an accountant sees all sales today and gains
   nothing new by seeing the orders those sales came from.

   The alternative — streaming open orders to all and finished ones only to
   their creator — was rejected because it strands rows. A colleague who
   delivers someone else's order would stop receiving it the moment it became
   terminal, so their device would keep the last copy it saw (`packed`) forever
   and show it as still needing delivery.

2. **`order_lines` stop being coupled to the orders in the same delta.**
   Today the line query is filtered by `whereIn('order_id', $orders->pluck('id'))`,
   which only worked because a device that could see an order had always seen it
   from creation. `pack` and `deliver` bump only the order's `sync_seq`, not its
   lines' — so under widened visibility a colleague's phone would receive an
   order **with no lines** and show a delivery with nothing in it. Lines now use
   the same plain delta as `sale_lines`.

3. **In-flight orders are pushed over every device's cursor by a migration.**
   Widening the filter does not retroactively deliver rows a device never asked
   for: the delta is `sync_seq > cursor`, so an order accepted last Tuesday sits
   below every colleague's cursor and would never arrive. An order waiting to be
   packed is exactly the case this feature exists for, so a one-off
   `sync_seq` bump on all **open** orders and their lines re-streams them on the
   next pull.

   Server-side and mechanism-native: an archive already bumps `sync_seq` so the
   client learns to hide a row, and this is the same move. It needs no client
   migration, no Dexie version and no forced full resync.

4. **Cancellation necessarily widens too, and that is correct.** The workflow
   spec says a salesman may cancel only orders they created — but that rule was
   enforced **solely by visibility**, never by a check. Widening the pull hands
   every salesman the ability to cancel any open order.

   That is not a regrettable side effect; it is required by the case the spec
   itself named: *"a shop refusing goods at the door is the case that justifies
   cancelling a packed order."* If a colleague is making the delivery, the
   colleague is the one standing at that door. A creator-only cancel rule would
   let anyone deliver but leave them unable to record the refusal.

   Recorded here explicitly so the change is a decision rather than an accident.

5. **The salesman's screen shows the whole shop's orders, and says which are
   not theirs.** "My orders" stops being true, so the screen becomes "Orders".
   An order taken by someone else is marked, because picking up a colleague's
   delivery should be a deliberate act, not a misreading of your own list.

   `/api/v1/whoami` **already returns `user_id`**; the client simply never kept
   it. Nothing new is added to the sync payload to support this.

6. **Whose order it is stays a user id, not a name.** The phone has no user
   directory, and adding one is a new synced entity with its own DPDP questions.
   The screen therefore distinguishes *mine* from *not mine*, which is what
   deciding to deliver actually requires, and does not claim to say who. Naming
   the taker is left as a follow-up.

## Architecture

| Unit | Change |
|---|---|
| `Api\V1\SyncController::pull` | Orders and order lines become plain deltas; the per-creator branch and the `whereIn` coupling go. |
| Migration | One-off `sync_seq` bump on open orders and their lines. |
| `resources/js/main.jsx` | Keeps `user_id` from `whoami`; passes it to the screen. |
| `resources/js/offline/orders.js` | `buildOrderList` marks each order `mine`. |
| `resources/js/screens/Orders.jsx` | Marks another person's order; title becomes "Orders". |

Beats are deliberately **not** touched: a route is a plan for a person, while an
order is work the shop owes a customer. They filter differently for good reason.

## Error handling / edge cases

| Case | Behaviour |
|---|---|
| Two salesmen deliver the same order | The sale reuses the order's uuid, so `createSale` returns the existing row — one sale, as today |
| A colleague delivers while the taker is offline | The taker's next pull shows it delivered; no conflict, status is forward-only |
| A colleague cancels at the door | Allowed (Decision 4); the taker learns on their next pull |
| An order below a device's cursor | Re-streamed by the migration if open; finished orders stay where they are, being history the device does not need |
| Order arrives without its lines | No longer possible — lines delta independently (Decision 2) |
| Accountant pulls | Receives orders, as they already receive every sale; still cannot write one (`roleAllows` unchanged) |

## Testing

- **Feature (PHP)** — a salesman pulls an order **taken by a different
  salesman** (replaces the old "streams a salesman only the orders they took");
  a second salesman can deliver one, creating exactly one sale; still no
  cross-tenant leak.
- **Feature (PHP)** — an order whose lines were written long before it moved
  still arrives **with its lines** when only the order was packed.
- **Migration (PHP)** — open orders and their lines are lifted above a prior
  cursor; terminal ones are left alone.
- **JS unit** — `buildOrderList` marks `mine` correctly, including a queued
  order (always mine) and an unknown user id.
- **Regression** — role gating on writes is unchanged; the accountant is still
  refused an order.

## Out of scope

Naming who took an order on the phone (needs a user directory); assigning a
specific deliverer; partial delivery; and any change to beats, stock or the
money path.

## Traceability

- Order workflow spec §Sync visibility → superseded by Decision 1.
- Order workflow spec §Permissions (salesman cancels only their own) →
  superseded by Decision 4.
- Backlog F-13 second known limitation → closed.
- Multi-tenant isolation → unchanged; RLS and `BelongsToTenant` still scope
  every query, and the widening is strictly *within* one tenant.
