<?php
// app/Services/InvoiceIssuer.php

namespace App\Services;

use App\Gst\GstCalculator;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\InvoiceCounter;
use App\Models\InvoiceLine;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues a tax invoice for an existing sale (PRD Phase 3).
 *
 * Invoicing is an explicit, ONLINE act — never automatic at sync — which is what
 * makes gapless numbering safe: an offline device can create sales all week
 * without ever needing to allocate a number.
 *
 * Everything the invoice shows is snapshotted here. A filed tax document is a
 * lawful exception to "always recomputable" (PRD §9): if a product's rate
 * changes next month, what was filed must not change with it.
 *
 * Assumes an already tenant-pinned transaction, like the other services.
 */
class InvoiceIssuer
{
    /**
     * @param  string|null  $buyerGstin  captured per-invoice; customers is
     *                                   offline-synced and must not gain a field
     *                                   only invoicing uses.
     *
     * @throws RuntimeException when the sale cannot lawfully be invoiced
     */
    public function issue(string $saleId, ?string $buyerGstin = null): Invoice
    {
        $businessId = (string) app('tenant.id');
        $business = Business::query()->findOrFail($businessId);

        if (blank($business->gstin)) {
            // An unregistered shop issuing a "tax invoice" would be claiming to
            // collect tax it cannot collect.
            throw new RuntimeException('This shop has no GSTIN, so it cannot issue a tax invoice.');
        }

        $sale = Sale::query()
            ->where('business_id', $businessId)
            ->with(['lines', 'customer'])
            ->findOrFail($saleId);

        if ($sale->reverses_id !== null) {
            throw new RuntimeException('A voided sale has no tax invoice.');
        }

        if (Invoice::query()->where('business_id', $businessId)->where('sale_id', $sale->id)->exists()) {
            throw new RuntimeException('This sale already has a tax invoice.');
        }

        $defaultRate = $business->default_gst_rate_percent;

        return DB::transaction(function () use ($businessId, $business, $sale, $buyerGstin, $defaultRate) {
            [$financialYear, $seq] = $this->allocate($businessId, Carbon::today());

            $taxableTotal = '0.00';
            $cgstTotal = '0.00';
            $sgstTotal = '0.00';
            $grandTotal = '0.00';
            $pending = [];

            foreach ($sale->lines as $line) {
                $pack = $line->productPack;
                $product = $pack?->product;
                $rate = $product?->gst_rate_percent ?? $defaultRate;

                if ($rate === null) {
                    throw new RuntimeException(
                        'No GST rate for "'.($product->name_en ?? $product->name_hi ?? 'a product').
                        '". Set a rate for it, or a shop default.'
                    );
                }

                $split = GstCalculator::extract((string) $line->line_total, (string) $rate);

                $taxableTotal = bcadd($taxableTotal, $split->taxableValue, 2);
                $cgstTotal = bcadd($cgstTotal, $split->cgst, 2);
                $sgstTotal = bcadd($sgstTotal, $split->sgst, 2);
                $grandTotal = bcadd($grandTotal, $split->lineTotal, 2);

                $pending[] = [
                    'description' => trim(($product->name_en ?: $product->name_hi ?: 'Item').' '.($pack?->packSize?->label ?? '')),
                    'hsn_code' => $product?->hsn_code,
                    'qty' => (int) $line->qty,
                    'rate' => (string) $line->rate,
                    'taxable_value' => $split->taxableValue,
                    'gst_rate_percent' => $split->ratePercent,
                    'cgst' => $split->cgst,
                    'sgst' => $split->sgst,
                    'line_total' => $split->lineTotal,
                ];
            }

            $invoice = new Invoice([
                'business_id' => $businessId,
                'sale_id' => $sale->id,
                'number' => sprintf('%s/%04d', $financialYear, $seq),
                'financial_year' => $financialYear,
                'seq' => $seq,
                'issued_on' => Carbon::today()->toDateString(),
                'buyer_name' => $sale->customer?->name ?? '—',
                'buyer_village' => $sale->customer?->village,
                'buyer_gstin' => $buyerGstin,
                'seller_gstin' => $business->gstin,
                'seller_state_code' => $business->state_code,
                'taxable_total' => $taxableTotal,
                'cgst_total' => $cgstTotal,
                'sgst_total' => $sgstTotal,
                'grand_total' => $grandTotal,
            ]);
            // Stamped before the insert: created_by is NOT NULL and is never
            // fillable from request input.
            $invoice->created_by = (int) app('tenant.user_id');
            $invoice->save();

            foreach ($pending as $row) {
                InvoiceLine::create($row + ['business_id' => $businessId, 'invoice_id' => $invoice->id]);
            }

            return $invoice->load('lines');
        });
    }

    /**
     * Next number in this business's financial-year series.
     *
     * Locked FOR UPDATE rather than MAX(seq)+1: two concurrent requests would
     * read the same maximum, and a unique index would then turn a legitimate
     * second invoice into an error instead of the next number.
     *
     * @return array{0: string, 1: int}
     */
    private function allocate(string $businessId, Carbon $date): array
    {
        // Indian financial year runs April–March.
        $startYear = $date->month >= 4 ? $date->year : $date->year - 1;
        $financialYear = sprintf('%d-%02d', $startYear, ($startYear + 1) % 100);

        $counter = InvoiceCounter::query()
            ->where('business_id', $businessId)
            ->where('financial_year', $financialYear)
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            $counter = InvoiceCounter::create([
                'business_id' => $businessId,
                'financial_year' => $financialYear,
                'next_seq' => 1,
            ]);
        }

        $seq = (int) $counter->next_seq;
        $counter->next_seq = $seq + 1;
        $counter->save();

        return [$financialYear, $seq];
    }
}
