<?php
// app/Ledger/LedgerEditor.php

namespace App\Ledger;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Correcting the khata by changing the row itself — edit, delete, restore.
 *
 * The other way of correcting is LedgerReverser, which never touches a row and
 * appends a negated mirror of it instead. That is still how the REST API and
 * the order workflow work, and it is what makes an ALREADY-DELIVERED figure
 * explainable. It is not what an owner wants when they simply typed 500 for
 * 450: the statement then reads "paid ₹500, reversed ₹500, paid ₹450" for what
 * was one payment, and the shop's own khata stops looking like the shop's own
 * khata. This class is the plain answer — the row says 450, and one line of
 * history explains itself by not being there.
 *
 * Delete is soft (SoftDeletes on both models). Two reasons, and the second is
 * the one that matters:
 *
 *  - invoices.sale_id and orders.sale_id are real foreign keys, so a hard
 *    delete either fails or orphans;
 *  - a delete has to be undoable. This is the screen where the wrong row gets
 *    tapped, and "restore" is the difference between a mistake and a loss.
 *
 * Everything that counts money reads through Eloquent, so the SoftDeletes
 * global scope removes a deleted row from outstanding, cash flow and the khata
 * without any of them knowing this class exists. The raw-builder reports are
 * the exception and join `sales` explicitly to filter it — see
 * DashboardReportService and FinishedGoodsService.
 *
 * What cannot be edited is guarded rather than silently allowed, because each
 * refusal is a figure someone outside the shop has already been shown.
 */
class LedgerEditor
{
    /**
     * Change a sale's date and its lines' quantities and rates.
     *
     * Lines are edited, never added or removed. A removed line would have to be
     * hard-deleted — sale_lines carry no deleted_at — and the phones learn about
     * rows by streaming them, not by being told one vanished, so the old line
     * would sit in the app's copy of this sale forever. Mirrors the order
     * revision form, which edits qty and rate for the same reason.
     *
     * list_rate and cost_at_sale are deliberately NOT re-derived: they are what
     * the pack listed and cost on the day it sold, and "did we sell this under
     * cost?" must keep the answer it had at the time.
     *
     * @param  array{sale_date: string, lines?: array<string, array{qty: int|string, rate: string}>}  $data
     *
     * @throws EditNotAllowed
     */
    public function updateSale(Sale $sale, array $data): Sale
    {
        $this->guardSale($sale);

        return DB::transaction(function () use ($sale, $data) {
            $total = '0.00';

            foreach ($sale->lines()->get() as $line) {
                $input = $data['lines'][$line->id] ?? null;

                if ($input !== null) {
                    $line->qty = (int) $input['qty'];
                    $line->rate = bcadd((string) $input['rate'], '0', 2);
                    $line->line_total = bcmul((string) $line->rate, (string) $line->qty, 2);
                    $line->save();
                }

                $total = bcadd($total, (string) $line->line_total, 2);
            }

            $sale->sale_date = $data['sale_date'];
            // Not fillable — recomputed here exactly as LedgerWriter computes it
            // at creation, never accepted from the form.
            $sale->total = $total;
            $sale->save();

            return $sale;
        });
    }

    /**
     * Take a sale off the khata. Outstanding drops by its total.
     *
     * Written as an assignment plus save() rather than delete(): SoftDeletes
     * runs its own UPDATE straight on the query builder, which skips the model
     * events — and `saving` is where HasSyncSequence stamps sync_seq. Without a
     * new sync_seq the delta pull never returns this row again and every phone
     * keeps showing a sale the shop deleted.
     *
     * @throws EditNotAllowed
     */
    public function deleteSale(Sale $sale): void
    {
        $this->guardSale($sale);

        $sale->deleted_at = now();
        $sale->save();
    }

    /** Put a deleted sale back. restore() saves, so sync_seq bumps and the phones follow. */
    public function restoreSale(Sale $sale): void
    {
        $sale->restore();
    }

    /**
     * Correct a payment in place — amount, date or mode.
     *
     * @param  array{payment_date: string, amount: string, mode: string}  $data
     *
     * @throws EditNotAllowed
     */
    public function updatePayment(Payment $payment, array $data): Payment
    {
        $this->guardPayment($payment);

        $payment->payment_date = $data['payment_date'];
        $payment->amount = bcadd((string) $data['amount'], '0', 2);
        $payment->mode = $data['mode'];
        $payment->save();

        return $payment;
    }

    /**
     * Take a payment off the khata. Outstanding rises back by its amount.
     *
     * @throws EditNotAllowed
     */
    public function deletePayment(Payment $payment): void
    {
        $this->guardPayment($payment);

        $payment->deleted_at = now(); // see deleteSale for why not delete()
        $payment->save();
    }

    public function restorePayment(Payment $payment): void
    {
        $payment->restore();
    }

    /**
     * Everything that makes a sale someone else's figure too.
     *
     * @throws EditNotAllowed
     */
    private function guardSale(Sale $sale): void
    {
        $this->guardReversalPair(
            (bool) $sale->reverses_id,
            Sale::where('reverses_id', $sale->id)->exists(),
        );

        // A tax invoice carries a government sequence number and is already in
        // the customer's hands. Changing what it was issued against would leave
        // an invoice that no longer describes any sale in this book.
        if (Invoice::where('sale_id', $sale->id)->exists()) {
            throw EditNotAllowed::invoiced(__('customers.cannot_edit_invoiced'));
        }

        // An order-delivered sale is the order's own figures. Correcting the
        // order re-issues the sale (OrderWriter::reviseOrder), so editing the
        // sale here would be silently undone the next time anyone touched the
        // order. The khata links to the order for exactly this case.
        if (Order::where('sale_id', $sale->id)->exists()) {
            throw EditNotAllowed::fromOrder(__('customers.cannot_edit_order_sale'));
        }
    }

    /** @throws EditNotAllowed */
    private function guardPayment(Payment $payment): void
    {
        $this->guardReversalPair(
            (bool) $payment->reverses_id,
            Payment::where('reverses_id', $payment->id)->exists(),
        );
    }

    /**
     * Rows written by the older append-only path, which the API still uses.
     *
     * Half of a reversal pair has no figures of its own — a reversal is defined
     * as the negation of its original, and the pair already nets to zero. Both
     * halves are refused: editing either one makes the pair stop cancelling, and
     * deleting either one revives the other's effect on the balance.
     *
     * @throws EditNotAllowed
     */
    private function guardReversalPair(bool $isReversal, bool $hasReversal): void
    {
        if ($isReversal) {
            throw EditNotAllowed::isReversal(__('customers.cannot_edit_reversal'));
        }

        if ($hasReversal) {
            throw EditNotAllowed::hasReversal(__('customers.cannot_edit_reversed'));
        }
    }
}
