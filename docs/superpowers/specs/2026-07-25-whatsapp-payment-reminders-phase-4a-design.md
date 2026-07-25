# WhatsApp Payment Reminders — Phase 4a Design

**Date:** 2026-07-25
**Status:** Draft (design); awaiting sign-off.
**Scope:** Phase 4a of WhatsApp payment reminders (PRD §18 Phase 2, "automated
WhatsApp reminders"). Gives the owner a reviewed **overdue list** and a one-tap
**wa.me deep link** that opens WhatsApp with a prefilled reminder in the
customer's language. No Meta API, no unattended sending — those are Phase 4b,
which this phase deliberately builds the foundation for.

---

## Background

Khata is a credit ledger: every sale that isn't paid raises `outstanding`, and
Phase 0 already surfaces the total plus a per-customer breakdown on the owner
dashboard. What the owner cannot do today is **act** on it. Chasing payment is
the single highest-value action in a distribution business, and it currently
happens entirely outside the product — the owner scrolls the khata, copies a
number into WhatsApp by hand, and retypes the amount.

Everything needed to compute *who to chase* is already recorded:

| Input | Source | Notes |
|---|---|---|
| Amount due | `KhataService::outstandingFor()` | `opening_balance + Σ sale.total − Σ payment.amount`, bcmath scale 2, always recomputable (PRD §9). |
| Who | `customers.name`, `customers.village` | Already modelled as `App\Reports\CustomerDue`. |
| Where to send | `customers.phone` | `string(20)`, **nullable** — a customer with no phone is unreachable and must be handled, not crashed on. |
| How stale | `payments.payment_date` | Most recent payment for the customer; absent = never paid. |

The gap is delivery. PRD §18 names the WhatsApp Business API, but that path is
blocked on a Meta app, business verification, and pre-approved templates — none
of which exist, and none of which are needed to deliver the *decision* half of
the feature. A `wa.me` click-to-chat link needs no API, no cost, and no template
approval, and it is exactly how the Indian khata apps this product competes with
(KhataBook, OkCredit) send reminders today.

## Decisions (locked in scoping)

1. **`wa.me` deep link now, Cloud API later — a 4a/4b split.** 4a ships the
   overdue computation, the review UI, the message composition and the send log.
   4b swaps only the *transport*: the same `ReminderMessage` text and the same
   `reminder_logs` row, sent unattended through the Cloud API. Splitting mirrors
   how Phase 2a/2b were split, and it means the hard, product-shaped half is not
   held hostage to Meta onboarding.

2. **Owner reviews, then sends — never unattended in 4a.** We compute the
   overdue list; the owner scans it and taps per customer. A human in the loop is
   the cheapest possible guard against wrong numbers, a customer who paid in cash
   this morning, and reminder spam — and it removes any need for a scheduler,
   quiet hours, or rate limiting in this phase.

3. **Platform-number semantics from day one.** In 4a the message physically
   leaves the *owner's own* WhatsApp (that is what a `wa.me` link does), but the
   message body is written as though it comes from the shop — it names the
   business, the amount and the shop's contact. When 4b moves sending to the
   single platform WABA number, the customer sees the same words from a
   different sender, not a different message. No per-tenant credentials are
   designed for, and none are stored.

4. **A send is logged, and the log is the 4b foundation.** Opening a `wa.me`
   link is fire-and-forget — we cannot observe delivery, and the owner may never
   press send. We therefore record **intent**, not delivery: a `reminder_logs`
   row written when the owner triggers the link, carrying the customer, the
   amount at that moment, the locale, the channel (`wa_link`), and who triggered
   it. This is what stops the same customer being chased twice in a day, gives
   4b a place to hang real delivery receipts (`channel = 'cloud_api'`, plus
   status columns), and gives DPDP a record of what was sent to whom.

5. **Overdue is `outstanding ≥ threshold` AND `days since last payment ≥ N`,
   both tenant-configurable with sane defaults.** A single "has any balance"
   rule would list every credit customer, which is noise. Defaults: `₹500` and
   `30` days. **Superseded 2026-07-25: the day threshold default is now 7, and
   both thresholds are editable on `/reminders` (they had no UI when this spec
   was written). See ui-backlog F-08.** Configuration lives on `businesses` as
   two columns, not a new
   settings table — two scalars do not earn a table.

6. **A customer with no phone is shown, not hidden.** They still owe money and
   the owner still needs to see it; the row simply renders "no phone on file"
   with the send control disabled and a link to edit the customer. Silently
   filtering them would quietly under-report what is owed.

7. **Consent is recorded per customer, and this phase captures it.** The
   existing `consents` table is **platform-level and keyed to `user_id`** — it
   covers owners and staff accepting the privacy policy, not a shop's customers.
   Messaging a customer is a distinct DPDP question with a distinct data
   subject, so 4a adds `customers.reminder_opt_out_at` (nullable timestamp) and
   an opt-out control. Opted-out customers appear in the list with sending
   disabled and a clear reason. This is deliberately the *minimum* honest
   mechanism: 4b, which sends unattended from a platform number, must extend it
   with inbound STOP handling before it can ship.

## The overdue definition

```
outstanding(customer)    = opening_balance + Σ sale.total − Σ payment.amount   (KhataService)
last_payment_on(customer)= max(payments.payment_date)                          (null if never paid)
days_overdue(customer)   = today − coalesce(last_payment_on, first_sale_date)

is_overdue(customer)     = outstanding        >= businesses.reminder_min_outstanding
                       AND days_overdue       >= businesses.reminder_min_days
                       AND reminder_opt_out_at IS NULL   → sendable
                                                          (opted-out still listed, not sendable)
```

Sorted by `outstanding` descending — chase the biggest debt first.

## Architecture

Follows the established owner-tool pattern (`ExpenseController`,
`SupplierController`, `ReportController`): Blade, **online-only**, owner-only,
via `ResolvesOwnedTenant` — the owned business is resolved from the caller's own
membership, never from the request, and all work runs tenant-pinned (RLS + an
explicit `business_id` scope, defense in depth).

### New pieces

| File | Role |
|---|---|
| `app/Services/ReminderService.php` | Computes the overdue list; tenant-pinned, read-only. Returns `list<OverdueCustomer>`. |
| `app/Reminders/OverdueCustomer.php` | Readonly VO: name, village, phone, outstanding, `daysOverdue`, `lastPaymentOn`, `sendable`, `blockedReason`. |
| `app/Reminders/ReminderMessage.php` | Composes the message text + the `wa.me` URL. The one place message wording lives, so 4b reuses it verbatim. |
| `app/Http/Controllers/Web/ReminderController.php` | `index` (review list), `store` (log a send, redirect to the `wa.me` URL), `optOut`/`optIn`. |
| `resources/views/reminders/index.blade.php` | The review table. |
| `database/migrations/*_create_reminder_logs_table.php` | The send log (RLS + app scope, registered in `TenantEraser`/`TenantExporter`). |
| `database/migrations/*_add_reminder_settings_to_businesses.php` | `reminder_min_outstanding`, `reminder_min_days`. |
| `database/migrations/*_add_reminder_opt_out_to_customers.php` | `reminder_opt_out_at`. |

### Message composition

Rendered from `lang/{en,hi}/reminders.php`, in the **customer-facing** language —
which is the tenant's configured locale, not the owner's UI locale, since the
customer reads it. Phone numbers are normalised to E.164 (`+91` default) before
being placed in the link; a number that cannot be normalised makes the row
non-sendable rather than producing a broken link.

```
https://wa.me/<e164-no-plus>?text=<urlencoded message>
```

### Route & access

- `GET  /reminders` — `reminders.index`, owner-only, alongside `/expenses`.
- `POST /reminders/{customer}` — logs intent, redirects to the `wa.me` URL.
- `POST /reminders/{customer}/opt-out` and `/opt-in`.

Not behind the write plan-gate, matching `/expenses`: a lapsed owner may still
chase their own money.

## Out of scope in 4a (deferred)

- **Cloud API sending, delivery receipts, inbound STOP** (→ 4b). 4b also needs
  quiet hours, per-tenant rate limits and a cost meter before unattended
  sending is safe.
- Scheduler / unattended batches (→ 4b) — `bootstrap/app.php` has no
  `withSchedule()` today and 4a does not add one.
- Per-tenant WhatsApp numbers (Decision 3), SMS fallback, message templating by
  the owner, reminder history *shown to* the owner, and bulk "remind everyone".

## Error handling / edge cases

| Case | Behaviour |
|---|---|
| No phone on file | Listed, send disabled, "no phone on file", link to edit customer. |
| Un-normalisable phone | Same as above with a distinct reason — never emit a broken `wa.me` link. |
| Opted out | Listed, send disabled, shows when they opted out; opt-in restores. |
| Negative outstanding (advance paid) | Excluded — they owe nothing. |
| Never paid, has sales | `days_overdue` counts from the first sale, not from null. |
| Nobody overdue | Empty state explaining the current thresholds and how to change them. |
| Same customer twice in one day | Second attempt warns (already reminded today) but is permitted — the owner may genuinely be re-sending. |
| Another tenant's customer id posted | 404 via the tenant pin; covered by a test. |

## Testing

- **Unit** — `ReminderServiceTest`: threshold and days rules at their
  boundaries, opt-out exclusion from *sendable* but not from the *list*, negative
  outstanding excluded, never-paid counted from first sale, tenant isolation.
- **Unit** — `ReminderMessageTest`: E.164 normalisation (10-digit, `+91`,
  spaces/dashes, un-normalisable), URL encoding, en and hi wording, amount
  formatting via `Inr`.
- **Feature** — `RemindersTest`: guest redirected, non-owner rejected, list
  renders with the right rows, send writes exactly one `reminder_logs` row and
  redirects to the right URL, opt-out disables sending, another tenant's
  customer 404s.
- **DPDP** — `reminder_logs` appears in `TenantEraser`/`TenantExporter` coverage
  tests alongside the other tenant tables.

## Traceability

- PRD §18 Phase 2 "automated WhatsApp reminders" → 4a delivers the targeting and
  composition; 4b delivers the automation.
- PRD §9 always-recomputable → the overdue list is derived from `KhataService`;
  `reminder_logs` records intent only and is never an input to outstanding.
- PRD §13 DPDP → per-customer opt-out (Decision 7), and `reminder_logs`
  registered for export/erase.
- Multi-tenant isolation (CLAUDE.md) → RLS **and** an explicit `business_id`
  scope on every new table and query.
