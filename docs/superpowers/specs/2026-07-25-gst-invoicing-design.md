# GST Invoicing — Design

**Date:** 2026-07-25
**Status:** Draft (design); awaiting sign-off.
**Scope:** PRD §18 Phase 3, "GST invoicing". Issue a numbered tax invoice for a
sale, with CGST/SGST extracted from GST-inclusive prices, and a print view.

---

## Background

`businesses.gstin` exists and nothing else does: no HSN, no tax rate, no tax
columns, and no print view anywhere in the app. Two properties of the existing
system constrain the design more than anything in the GST rules do:

1. **Khata outstanding is `Σ sales.total`.** Any design that changes how a sale
   total is computed retroactively rewrites what every customer owes.
2. **Sales are created offline and synced later.** GST requires gapless
   sequential invoice numbers, which a device that has been offline for three
   days cannot allocate without risking collisions or gaps.

Both are solved by the scoping decisions below rather than worked around later.

## Decisions

1. **Prices are GST-inclusive; tax is extracted, never added.** The rate a shop
   charges already includes GST, as Indian retail pricing does. The invoice works
   backwards from the amount charged:

   ```
   taxable = line_total ÷ (1 + rate/100)
   tax     = line_total − taxable
   cgst = sgst = tax ÷ 2
   ```

   **The invoice total therefore equals the sale total, exactly.** Khata,
   outstanding, cash flow and every report are untouched, no existing debt moves,
   and the offline sale screen needs no change. This is enforced as an invariant,
   not left to rounding luck (Decision 4).

2. **An invoice is an explicit, online act.** A sale is a sale; the owner presses
   "Create tax invoice" on the ones that need one. An offline device never
   allocates a number, so gapless numbering is trivially safe — and it matches
   how the business actually works: villagers buying on khata do not need tax
   invoices, registered buyers do.

3. **Intra-state only: CGST + SGST at half the rate each.** No IGST, no place-of-
   supply comparison, and therefore **no state field on `customers`** — which
   matters because `customers` is offline-synced and a schema change there would
   reach into Dexie and the React forms. Inter-state is a later phase.

4. **Numbers are gapless, allocated under a row lock.** A dedicated
   `invoice_counters` row per (business, financial year) is locked
   `FOR UPDATE` and incremented. Computing `MAX(seq)+1` would let two concurrent
   requests read the same maximum, and a unique index alone turns that into an
   error rather than a correct number. Format: `2026-27/0001`, financial year
   running April–March as Indian law defines it.

5. **An invoice is immutable and self-contained.** Its lines snapshot the
   description, HSN, quantity, rate, taxable value, GST rate and tax split at the
   moment of issue. Product rates change; a reissued invoice must not silently
   change what a filed document says. Nothing on an invoice is recomputed from
   live product data after issue.

6. **The rounding rule is stated, and the total is reconciled.** Money is bcmath
   scale 2. Tax is computed per line and the halves are split with any odd paisa
   assigned to CGST, then the invoice totals are the sums of the line values —
   so `Σ(taxable + cgst + sgst) == sale.total` holds exactly, by construction.
   A test asserts it on amounts chosen to be awkward (e.g. ₹99.99 at 5%).

7. **Buyer GSTIN is captured on the invoice, not on the customer.** Entered on
   the invoice form and snapshotted. Again this avoids migrating the
   offline-synced `customers` table for a field only invoicing uses.

8. **One invoice per sale.** A unique constraint on `sale_id`. Re-invoicing a
   sale is not a silent second document — cancellation and reissue is a
   deliberate later feature, not an accident waiting to happen.

## Schema

| Table | Purpose |
|---|---|
| `invoices` | id, business_id, sale_id (**unique**), number, financial_year, seq, issued_at, buyer_name, buyer_gstin, buyer_village, seller snapshot (gstin, state_code), taxable_total, cgst_total, sgst_total, grand_total, created_by. RLS + app scope. |
| `invoice_lines` | invoice_id, description, hsn_code, qty, rate, taxable_value, gst_rate_percent, cgst, sgst, line_total. |
| `invoice_counters` | business_id + financial_year → next_seq. The lock target (Decision 4). |
| `products.hsn_code`, `products.gst_rate_percent` | Per-product, nullable; fall back to the business default. |
| `businesses.default_gst_rate_percent`, `businesses.state_code` | Shop-wide default rate and the state shown on the invoice. |

`products` is offline-synced, so the two new columns are **server-side only** and
are deliberately not added to the API whitelist — the sync payload is unchanged,
exactly as `customers.reminder_opt_out_at` was handled in Phase 4a.

## Architecture

- `App\Gst\GstCalculator` — pure, no DB: inclusive extraction, CGST/SGST split,
  reconciliation. The whole tax rulebook, testable in isolation.
- `App\Services\InvoiceIssuer` — allocates the number under lock, snapshots
  lines, writes the invoice in one transaction.
- `Web\InvoiceController` — owner-only Blade (list, create from a sale, print),
  same `ResolvesOwnedTenant` pattern as the other owner tools.
- `Web\GstSettingsController` — set the shop default rate, state code, and the
  per-product HSN/rate table. Blade, so React and the offline layer are untouched.

## Out of scope

IGST / inter-state, e-invoicing and IRN, e-way bills, reverse charge, composition
scheme, credit/debit notes, invoice cancellation and reissue, GSTR filing exports
(Tally export is its own Phase 3 item), and per-customer GSTIN storage.

## Error handling / edge cases

| Case | Behaviour |
|---|---|
| Sale already invoiced | Refused; the existing invoice is linked instead. |
| Sale is a reversal, or is itself reversed | Refused — a void has no tax invoice. |
| Product has no rate | Falls back to the business default; if that is unset too, the form refuses and says which products need a rate. |
| Rate is 0% | Valid (exempt goods): taxable equals the line total, tax zero. |
| Odd paisa in the CGST/SGST split | Remainder to CGST, so the halves still sum to the tax exactly (Decision 6). |
| Negative-qty (return) line | Carried through with negative values; the invoice still reconciles to the sale total. |
| Another tenant's sale id | 404 via the tenant pin. |
| Business has no GSTIN | Invoicing refused with an explanation — an unregistered shop must not issue tax invoices. |

## Testing

- **Unit** — `GstCalculatorTest`: extraction at 0/5/12/18%; the awkward-amount
  reconciliation invariant; odd-paisa split; negative (return) lines.
- **Unit** — `InvoiceIssuerTest`: numbering starts at 0001 and increments;
  financial-year rollover resets the series; the invoice total equals the sale
  total; lines snapshot rather than reference; refuses a double invoice, a
  reversal, and a shop with no GSTIN.
- **Feature** — `InvoicesTest`: owner-only access, create from a sale, print view
  renders the required fields (seller GSTIN, buyer, HSN, rate-wise tax split,
  invoice number, date), tenant isolation.
- **Regression** — sale totals, khata outstanding and every dashboard figure are
  unchanged by issuing an invoice; asserted explicitly.

## Traceability

- PRD §18 Phase 3 "GST invoicing" → this phase, intra-state.
- PRD §9 always-recomputable → invoices are the deliberate exception: a filed tax
  document is a snapshot by law, and Decision 5 says so explicitly.
- Multi-tenant isolation → RLS **and** an explicit `business_id` scope on both
  new tenant tables.
