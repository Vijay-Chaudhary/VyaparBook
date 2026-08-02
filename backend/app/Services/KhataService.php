<?php
// app/Services/KhataService.php

namespace App\Services;

use App\Models\Customer;
use App\Models\ProductPack;
use Illuminate\Support\Collection;

class KhataService
{
    /**
     * outstanding = opening_balance + Σ sale.total − Σ payment.amount.
     *
     * Reversals are ordinary rows with negated amounts, so they self-cancel here
     * and no row is ever excluded or mutated — "outstanding is always
     * recomputable" (PRD §9). bcadd/bcsub at scale 2: rupees must not drift.
     */
    public function outstandingFor(Customer $customer): string
    {
        // CAST(... AS CHAR) so the sums come back as exact decimal strings,
        // never PHP floats that could already have drifted before bcmath sees
        // them.
        $sales = (string) $customer->sales()->selectRaw('CAST(coalesce(sum(total), 0) AS CHAR) as agg')->value('agg');
        $paid = (string) $customer->payments()->selectRaw('CAST(coalesce(sum(amount), 0) AS CHAR) as agg')->value('agg');

        $balance = bcadd((string) $customer->opening_balance, $sales, 2);

        return bcsub($balance, $paid, 2);
    }

    /**
     * A time-ordered khata statement: every sale and payment for the customer as
     * one stream with a running balance. Debits (sales) raise it, credits
     * (payments) lower it; the last running value equals outstandingFor().
     */
    public function ledgerFor(Customer $customer): Collection
    {
        $entries = $customer->sales()->get()
            ->map(fn ($s) => [
                'kind' => $s->reverses_id ? 'sale_reversal' : 'sale',
                'ref' => $s,
                'date' => $s->sale_date,
                'delta' => (string) $s->total,              // debit: +
            ])
            ->concat($customer->payments()->get()->map(fn ($p) => [
                'kind' => $p->reverses_id ? 'payment_reversal' : 'payment',
                'ref' => $p,
                'date' => $p->payment_date,
                'delta' => bcmul((string) $p->amount, '-1', 2), // credit: −
            ]))
            ->sortBy([
                ['date', 'asc'],
                fn ($a, $b) => $a['ref']->created_at <=> $b['ref']->created_at,
            ])
            ->values();

        $running = (string) $customer->opening_balance;

        return $entries->map(function ($e) use (&$running) {
            $running = bcadd($running, $e['delta'], 2);
            $e['running_balance'] = $running;

            return $e;
        });
    }

    /** The rate to freeze onto a new sale line — one home for that decision. */
    public function snapshotRate(ProductPack $pack): string
    {
        return (string) $pack->default_sell_price;
    }
}
