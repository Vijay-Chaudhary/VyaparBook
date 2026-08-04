<?php
// app/Ledger/LedgerReverser.php

namespace App\Ledger;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Services\LedgerWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * The one home for corrections, mirroring LedgerWriter's shape.
 *
 * A sale row IS a khata entry and every figure in the app is built on it —
 * outstanding, cash flow, COGS, finished goods, GST invoices, the overdue list
 * that drives reminders. So a correction is **append-only**: it writes a NEW
 * row with the amounts negated, pointing back at the original through
 * `reverses_id`. The original stays byte-for-byte intact.
 *
 * That is what makes "delete a sale" safe. Nothing is removed, so nothing
 * recomputes wrongly and no already-issued document stops matching the books;
 * the ledger reads "sale ₹500, voided ₹500" instead of a gap where a sale used
 * to be.
 *
 * This logic lived inside SaleController and PaymentController. It moved here
 * when the owner's Blade ledger became a second caller — duplicating the guards
 * across two controllers is exactly how they drift apart.
 */
class LedgerReverser
{
    public function __construct(private readonly LedgerWriter $writer) {}

    /**
     * Void a sale by writing its mirror image.
     *
     * The reversal copies each line's frozen rate and negates only the
     * quantity, so a sale voided after a price change still cancels to exactly
     * zero rather than to today's price.
     *
     * @throws ReversalNotAllowed
     */
    public function voidSale(Sale $original): Sale
    {
        $this->guard(
            (bool) $original->reverses_id,
            Sale::where('reverses_id', $original->id)->exists(),
            __('customers.cannot_void_reversal'),
            __('customers.already_voided'),
        );

        return DB::transaction(function () use ($original) {
            $reversal = new Sale([
                'business_id' => app('tenant.id'),
                'uuid' => (string) Str::uuid(), // a void has no client uuid
                'customer_id' => $original->customer_id,
                // Dated today, not backdated to the original: the correction is
                // an event of its own, and rewriting a past day's totals is the
                // thing this whole approach exists to avoid.
                'sale_date' => now()->toDateString(),
                'reverses_id' => $original->id,
            ]);
            $reversal->created_by = app('tenant.user_id');
            $reversal->total = bcmul((string) $original->total, '-1', 2);
            $reversal->save();

            foreach ($original->lines as $line) {
                $r = new SaleLine([
                    'business_id' => app('tenant.id'),
                    'sale_id' => $reversal->id,
                    'product_pack_id' => $line->product_pack_id,
                    'qty' => -$line->qty,
                    'rate' => $line->rate, // same frozen rate, negated qty
                ]);
                $r->list_rate = $line->list_rate;
                // Mirrored, not re-derived: costs move, and a void must cancel
                // the sale that happened rather than the one today's cost
                // would describe.
                $r->cost_at_sale = $line->cost_at_sale;
                $r->line_total = bcmul((string) $line->line_total, '-1', 2);
                $r->save();
            }

            return $reversal;
        });
    }

    /**
     * Reverse a payment. Outstanding rises back by the reversed amount.
     *
     * @throws ReversalNotAllowed
     */
    public function reversePayment(Payment $original): Payment
    {
        $this->guard(
            (bool) $original->reverses_id,
            Payment::where('reverses_id', $original->id)->exists(),
            __('customers.cannot_reverse_reversal'),
            __('customers.already_reversed'),
        );

        $reversal = new Payment([
            'business_id' => app('tenant.id'),
            'uuid' => (string) Str::uuid(), // a reversal has no client uuid
            'customer_id' => $original->customer_id,
            'payment_date' => now()->toDateString(),
            'amount' => bcmul((string) $original->amount, '-1', 2),
            'mode' => $original->mode,
            'reverses_id' => $original->id,
        ]);
        $reversal->created_by = app('tenant.user_id');
        $reversal->save();

        return $reversal;
    }

    /**
     * Correct a payment: reverse what was recorded, then record what was meant.
     *
     * Editing the row in place is the one thing that must not happen. A payment
     * IS a khata entry, so a silent amount change would move a balance the
     * customer has already been shown with nothing on the books to explain it.
     * The statement instead reads "paid ₹500, reversed ₹500, paid ₹450".
     *
     * The corrected payment's uuid is derived from the one being corrected, so
     * a double-submitted correction replays onto the same row rather than
     * crediting twice — recordPayment is idempotent by uuid. Correcting a
     * correction derives from ITS uuid in turn, so the chain never collides
     * and needs no counter column.
     *
     * @param  array{payment_date: string, amount: string, mode: string}  $data
     *
     * @throws ReversalNotAllowed when the original is itself a reversal, or is
     *                            already reversed
     */
    public function correctPayment(Payment $original, array $data): Payment
    {
        return DB::transaction(function () use ($original, $data) {
            // Guards live in reversePayment; letting it run first means a
            // refusal happens before anything is written.
            $this->reversePayment($original);

            [$corrected] = $this->writer->recordPayment([
                'uuid' => (string) Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    "vyaparbook:payment:{$original->uuid}:correction",
                ),
                'customer_id' => $original->customer_id,
                'payment_date' => $data['payment_date'],
                'amount' => $data['amount'],
                'mode' => $data['mode'],
            ]);

            return $corrected;
        });
    }

    /**
     * Both refusals, in the order that matters: a row cannot be both, and
     * "this is a reversal" is the more precise thing to say when it is.
     */
    private function guard(bool $isReversal, bool $alreadyReversed, string $isReversalMessage, string $alreadyMessage): void
    {
        if ($isReversal) {
            throw ReversalNotAllowed::isReversal($isReversalMessage);
        }

        if ($alreadyReversed) {
            throw ReversalNotAllowed::alreadyReversed($alreadyMessage);
        }
    }
}
