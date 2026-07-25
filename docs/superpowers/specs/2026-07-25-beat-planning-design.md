# Salesman Beat Planning — Design

**Date:** 2026-07-25
**Status:** Shipped.
**Scope:** PRD §18 Phase 3, "salesman route/beat planning". Owner plans beats;
the assigned salesman sees today's customers on their phone, offline.

---

## Decisions

1. **A beat is a weekday cycle, not a calendar.** "Rampur runs Mondays and
   Thursdays" repeats forever with nothing to maintain. Date-specific planning
   was rejected: the day someone stops planning, the salesman's screen goes
   empty, and an empty screen reads as broken rather than as "nothing today".

2. **Read-only in the field.** The phone reads beats and never writes them, so
   `beats`/`beat_customers` need no `uuid` idempotency key, no push endpoint,
   no outbox path and no conflict rule. Recording visits is a later phase
   precisely because it needs all four, and the sale/payment push path shows how
   much care that takes.

3. **First new offline-synced entities since the core.** They carry `sync_seq`
   and ride the existing delta pull, so the client gains two Dexie stores
   (schema v5) and no new transport.

4. **A salesman pulls only their own beats.** Another salesman's route is not
   their business; withholding it at the app layer is defense in depth over
   RLS's tenant scope, exactly as stock is withheld from non-managers.
   Membership rows follow their beat, so nothing on the device points at a beat
   it does not hold.

5. **Unassigned beats are visible to everyone.** In a small shop the owner often
   works a route themselves, and hiding unassigned beats would leave that day
   looking empty.

6. **Archive, never delete.** An archived beat still streams, so devices holding
   it learn to drop it — the same mechanism archived customers already use.

## Out of scope

Visit recording (check-in, outcome, skip reason), route optimisation or
distance, per-beat targets, and beat-wise performance reporting.

## Testing

- **Unit (PHP)** — `BeatServiceTest`: weekday matching, per-salesman filter,
  call order, archived exclusion, tenant isolation, `sync_seq` stamping.
- **Feature (PHP)** — `SyncPullBeatsTest`: manager sees all, salesman sees only
  theirs with matching links, no cross-tenant leak, cursor advances to an empty
  second delta. `BeatsTest`: CRUD, weekday requirement, membership check,
  call-order rebuild, cross-tenant refusals, archive-not-delete.
- **Unit (JS)** — `beats.test.js`: ISO weekday mapping (Sunday = 7), today's
  filter, call order, unassigned visible, archived hidden, uncached customer
  skipped rather than rendered blank.
