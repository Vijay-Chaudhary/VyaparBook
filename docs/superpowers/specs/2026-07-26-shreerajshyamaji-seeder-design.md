# Shree Raj Shyama Ji Namkeen — real-data seeder

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

**Date:** 2026-07-26
**Status:** Approved, ready for implementation planning

## Goal

Replace the invented demo data with the real records of Shree Raj Shyama Ji
Namkeen (Hata), so local development, screenshots and demos run against a
business that actually exists. The owner supplied masters and transactions
covering April–June 2026.

Two things follow from that and are in scope:

1. The app's fixed vocabularies do not cover the owner's masters. Raw materials
   are bought in **Tina** (tins of oil — the single largest line of spend), and
   expenses include **Diesel** and **Packing Material**. Neither is expressible
   today, so both vocabularies are extended.
2. The existing `DemoDataSeeder` and its two fictional tenants are deleted.

## Decisions

| Question | Decision |
|---|---|
| Transactions naming parties absent from the masters | Real names win; the five customers that appear only in transactions are genuine and are added. Two phantom *suppliers* in the 04-Jun purchases are remapped to the real suppliers who supply those materials on every other date. |
| Bhim ji's two 07-Jun Senvda 1kg lines (50 @ ₹76 and 40 @ ₹95) | Not a duplicate. Both seed. |
| Rates differing per customer for the same pack | Correct and intentional. Each sale line seeds the rate actually charged; the pack's `default_sell_price` is the modal rate. |
| Vocabulary gap (Tina, Bag, Dozen, Diesel, Packing Material) | Extend the app so the data seeds faithfully and stays editable, rather than distorting quantities to fit. |
| Demo tenants | Deleted. Shree Raj Shyama Ji Namkeen is the only business. |
| Production/consumption not balancing (below) | Reconstruct production from a per-kilo recipe so the costing is truthful; the owner's `Consumed` and `Closing Stock` columns are superseded. |
| Customer phone numbers (not supplied) | Left null. Consequence accepted: `ReminderService` blocks every customer as `no_phone`. |

### The production problem, and why it is overridden

The owner's figures do not balance:

| | |
|---|---|
| Sales | 1,653.8 kg, ₹1,69,123 → **₹102/kg** revenue |
| Production logged | 3 batches, **770 kg** (Sev 425, Mix Sev 345, **Senvda 0**) |
| Material consumed | **₹2,34,342** |

`CogsService` computes product ₹/kg as `Σ batch cost ÷ Σ output_kg`. Seeding
those figures verbatim yields **₹304/kg cost against ₹102/kg revenue** — every
sale a heavy loss — and Senvda, the top seller at 1,049 kg, has no batch at all,
so it would fall back to an estimated cost.

Two specific faults: sales are 2.1× logged output, and the oil line (57 Tina ≈
855 kg for 1,654 kg of output) implies ~52% oil absorption where frying namkeen
absorbs 15–25%.

The purchases, sales and payments are internally consistent and seed verbatim.
Only production and consumption are reconstructed.

## Scope

### Deleted

- `database/seeders/DemoDataSeeder.php`, with the `Demo Namkeen Bhandar` and
  `Demo Sweets House` tenants and all their invented data. Referenced only by
  `DatabaseSeeder`; nothing else depends on it.

### Kept

- The platform superadmin (`admin@vyaparbook.test`), moved into
  `DatabaseSeeder`. It is admin-console infrastructure, not demo business data.
- `DatabaseSeeder`'s existing owner (`owner@vyaparbook.test`) and the
  `Shree Raj Shyama Ji Namkeen` business record (Hata, `hi`, trial).

### Added

```
database/seed_data/shreerajshyamaji/
  catalog.php      3 products, 17 pack sizes, 21 product packs
  customers.php    40 customers
  suppliers.php    6 suppliers
  materials.php    16 raw materials
  purchases.php    23 purchases
  sales.php        103 sale lines grouped by customer + date
  payments.php     payment rows from the bill ledger
  production.php   reconstructed batches and material consumptions
database/seeders/ShreeRajShyamajiSeeder.php
app/Stock/MaterialUnit.php
```

One file per master, following the existing `database/catalog_templates/*.php`
convention. The seeder walks them and holds no business data itself, so a
re-export from the owner's sheet replaces a data file rather than editing code.

### Changed

- **`app/Stock/MaterialUnit.php`** (new) holds the unit list once, modelled on
  the existing `App\Expenses\ExpenseCategory`. The list is currently duplicated
  in `RawMaterialController::UNITS` and `TenantImporter::UNITS`; both are
  repointed at it. Adds `bag`, `dozen`, `tina` to the existing `kg`, `litre`,
  `piece`, `gram`, `ml`, `packet` — `ml` is retained, since removing it could
  orphan existing rows.
- **`App\Expenses\ExpenseCategory`** gains `diesel` and `packing_material`
  (6 keys → 8), with labels in `lang/en/expenses.php` and `lang/hi/expenses.php`.
  The owner's "Salary" maps to the existing `salaries`, "Miscellaneous" to
  `other`.
- **`DatabaseSeeder`** calls `ShreeRajShyamajiSeeder` instead of
  `DemoDataSeeder`.

## Data specification

Every write goes through the privileged `pgsql_migrate` connection, as
`DemoDataSeeder` did and for the same reason: a seeder runs outside a request,
so no `SetTenantContext` transaction has set `app.current_tenant`, and RLS
`WITH CHECK` would reject tenant-owned inserts on the app connection. Guarded
columns (`created_by`, `total`, `line_total`) are set by explicit assignment,
never mass-assigned.

### Catalog

Products: Senvda (सेंवड़ा), Sev (सेव), Mix Sev (मिक्स सेव).

Pack sizes: 17 — the owner's 15 (300 g to 1 kg in 50 g steps) plus **250 g** and
**375 g**, which appear in sales but not the master list. `in_dropdown` is true
for the 10 sizes actually sold (250, 300, 350, 375, 400, 700, 800, 900, 950,
1000 g) and false for the other 7, so the order screen offers only live sizes.

Product packs: 21, one per product/pack combination actually sold.
`default_sell_price` is the modal rate for that combination.

| Product | Packs sold | Rate range |
|---|---|---|
| Senvda | 300g, 350g, 375g, 400g, 700g, 800g, 900g, 1kg | ₹30 – ₹110 |
| Sev | 350g, 400g, 800g, 900g, 950g, 1kg | ₹38 – ₹130 |
| Mix Sev | 250g, 300g, 350g, 400g, 800g, 900g, 1kg | ₹32 – ₹130 |

### Customers

40 rows: the 35-name master plus 5 that appear only in transactions —
Dwivedi ji (Aziz), Ghore lal (Mathauli), Madhav (Ragarganj), Munna Singh
(Nandu Mundera), Parthiv (Khairatwa).

Name and village split on `" - "`. `opening_balance` is `0.00` for all: every
balance derives from seeded sales and payments, never stored. `phone` is null
throughout.

Two name collisions are preserved as distinct customers — Santosh Singh in Aziz
and in Harpur, Vikash ji in Asna and in Lohepar. The UI shows village beneath
the name, so they remain distinguishable.

Ten master customers have no transactions and correctly seed with a zero
balance: Ashish (Ragar ganj), Chotte lal (Mathauli), Dharmendra ji
(Khaurantanwa), Dilip ji (Aziz), Gurudev ji (Jhanga), Mishra ji (Tinahawan),
Munna Singh (Ahirauli thana), Sahil ji (Laxmipur), Star ji (Mathauli), Vinod
gupta (Lohepar).

### Suppliers

6 rows, `opening_balance` `0.00`: Kamakhya GKP, Floar Mill Hata, Balaji Trader
Hata, PPP (Panni) Shambu GKP, PPP (Bora) Shambu GKP, LDO Supplier.

No supplier payments were supplied, so the full ₹3,42,305 of purchases seeds as
outstanding payable.

### Raw materials

16 rows with the owner's units and reorder levels; opening stock `0.00` as the
sheet states.

**Assumption:** the master lists Black Salt in `Packet`, but the purchase records
50.00 **Kg** at ₹25 — the same rate as White Salt. Seeded as `kg`.

### Purchases

All 23 rows verbatim, ₹3,42,305 total, dated 21-Apr to 04-Jun 2026.

Two rows name suppliers absent from the master and are remapped:

| Date | Material | Sheet says | Seeded as |
|---|---|---|---|
| 04-Jun-2026 | Maida | Spice World Traders | Floar Mill Hata |
| 04-Jun-2026 | Refined Oil | PackTech Industries | Balaji Trader Hata |

### Sales

103 lines grouped by customer and date into sales. Each line carries the rate
actually charged (`amount ÷ qty`), not the pack default.

Byash ji's 11-Jun line of **−9 packs / −₹666** seeds as a negative-qty return
line. The schema supports this directly: `sale_lines.qty` is a signed integer
documented as "negative qty = a return line (PRD §7 returns)".

Totals: ₹1,69,123 over 1,653.8 kg.

| Product | kg sold | Revenue | ₹/kg |
|---|---|---|---|
| Senvda | 1,048.8 | ₹97,292 | ₹92.77 |
| Sev | 401.6 | ₹45,951 | ₹114.42 |
| Mix Sev | 203.3 | ₹25,880 | ₹127.30 |

### Payments

The payment rows from the bill ledger, against the customer and date recorded.
Payments settle most accounts; several customers carry a genuine balance, which
is what makes the khata and overdue screens meaningful.

### Production (reconstructed)

The owner's three dated batches are kept (15-May Sev 345 kg, 16-May Sev 80 kg,
17-May Mix Sev 345 kg). Further batches are added to cover what was sold, dated
ahead of the sales they supply.

Consumption is derived from a per-kilo recipe rather than the sheet's totals:

Seven batches, the owner's three among them:

| Date | Product | Output |
|---|---|---|
| 2026-04-25 | Senvda | 400.0 kg |
| 2026-05-15 | Sev | 345.0 kg *(owner's)* |
| 2026-05-16 | Sev | 80.0 kg *(owner's)* |
| 2026-05-17 | Mix Sev | 345.0 kg *(owner's)* |
| 2026-05-20 | Senvda | 400.0 kg |
| 2026-06-05 | Senvda | 355.0 kg |
| 2026-06-05 | Sev | 20.0 kg |

Total 1,945 kg against 1,653.8 kg sold. Senvda and Sev are sized to ~110% of
what each sold; Mix Sev is left at the owner's recorded 345 kg, which already
exceeds its 203.3 kg of sales.

Every kilo of output consumes 0.85 kg of flour, 0.20 kg of absorbed oil, and
₹4.00 of packing and salt. Oil is costed at ₹167.14/kg (₹2,507.14 per 15 kg
Tina). The flour blend differs per product:

| Product | Flour per kg output | Cost | Revenue | Margin |
|---|---|---|---|---|
| Senvda | 100% maida | ₹62.08 | ₹92.77 | 33.1% |
| Sev | 50% besan / 50% chawal anta | ₹73.13 | ₹114.42 | 36.1% |
| Mix Sev | 60% besan / 15% peanuts / 25% chawal anta | ₹87.32 | ₹127.30 | 31.4% |

Two hard constraints, both verified against these ratios:

1. **No material may consume more than was purchased** — closing stock must not
   go negative for any of the 16 materials.
2. **Output must cover the 1,653.8 kg sold.**

Resulting consumption against purchases:

| Material | Consumed | Purchased | Closing |
|---|---|---|---|
| Maida | 981.75 kg | 2,500 kg | 1,518.25 kg |
| Besan | 365.07 kg | 400 kg | 34.93 kg |
| Chawal Anta | 262.44 kg | 400 kg | 137.56 kg |
| Peanuts | 43.99 kg | 100 kg | 56.01 kg |
| Refined Oil | 25.93 Tina | 70 Tina | 44.07 Tina |

Besan is the binding constraint, and it is what sets the blends. Pure-besan Sev
needs ~445 kg against the 400 kg purchased; even a 70/30 blend overruns. Holding
the owner's 345 kg Mix Sev batch (which alone wants 235 kg of besan at an 80/20
blend) forces both products onto leaner blends — hence 50/50 for Sev and a
three-way blend for Mix Sev. Chawal anta absorbs the difference at the same
₹29/kg as maida, and peanuts stay well inside the 100 kg bought.

Oil consumption lands at 25.93 Tina against the 57 the sheet claimed. This
supersession is deliberate — see "The production problem".

Packing materials (Panni, Bora) and salt are consumed proportionally to output
within the ₹4.00/kg allowance — ₹7,780 across 1,945 kg, against ₹13,365 of such
materials purchased — bounded by the same non-negative constraint.

## Superseded source figures

Recorded so the override is traceable rather than lost:

| Material | Sheet: consumed | Sheet: closing | Status |
|---|---|---|---|
| Refined Oil | 57 Tina | 13 Tina | Superseded — implies ~52% oil absorption; recomputed to 25.93 Tina |
| Maida | 1,700 kg | 800 kg | Superseded — recomputed to 981.75 kg |
| Besan | 250 kg | 150 kg | Superseded — recomputed to 365.07 kg |
| Chawal Anta | 250 kg | 150 kg | Superseded — recomputed to 262.44 kg |
| *(all other materials)* | — | — | Superseded; recomputed |

Purchased quantities and every purchase row are **not** superseded.

## Verification

`tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php`:

- Masters land: 40 customers, 6 suppliers, 16 materials, 3 products, 17 pack
  sizes, 21 product packs, all on the one business.
- Purchases: 23 rows totalling ₹3,42,305.
- Sales: 103 lines totalling ₹1,69,123 over 1,653.8 kg, and every sale satisfies
  the writer invariant `total = Σ line_total`.
- Payments: 46 rows totalling ₹1,26,229, leaving **₹42,894 outstanding** across
  22 customers; 8 transacting customers settle to zero and 10 master customers
  never transact.
- Outstanding reconciles: Σ per-customer outstanding equals Σ sales − Σ payments.
  Spot-checked by hand against the ledger — Ghore lal carries ₹9,365, Byash ji
  ₹985 after the return, Manish ji ₹5 (his ₹9,125 bill was paid ₹9,120).
- **No material closes negative.** This is the check that catches a bad recipe.
- Each product's gross margin falls between 20% and 40%, so a later edit cannot
  silently reintroduce the ₹304/kg problem.
- Idempotent: `db:seed` run twice leaves identical row counts. Masters use
  `updateOrCreate` on natural keys; transactional data is guarded by an
  "already populated" check, as the existing seeder does.

Vocabulary:

- `tests/Unit/ExpenseCategoryTest.php` — **updated**; it pins the exact key list.
  Gains `diesel` and `packing_material`, plus a check that every key has a label
  in both `lang/en` and `lang/hi` (a missing Hindi label is otherwise invisible
  until someone switches language).
- `tests/Unit/MaterialUnitTest.php` — new, for the extracted list.
- A feature test that the API accepts a raw material with `unit: tina` and still
  rejects an unknown unit.
- `tests/Feature/Stock/RawMaterialCrudTest.php` and
  `tests/Unit/RawMaterialModelTest.php` reference units; check for assertions on
  the old list and update if present.

Deliberately not asserted: the sheet's `Consumed` and `Closing Stock` columns,
which approach B supersedes.

## Out of scope

- **Expenses.** Eight categories were supplied but no expense transactions, so
  the categories are created and zero expenses seeded. The P&L will show gross
  profit with no operating costs beneath it. If a real expense sheet
  (salaries, electricity, diesel for May–June) arrives later, it seeds through
  the same mechanism.
- **Supplier payments.** None supplied; all purchases stay outstanding.
- **Customer phone numbers.** Left null by decision, which disables reminder
  sending for every customer.
- **Orders.** No order data was supplied; the order workflow seeds empty.
