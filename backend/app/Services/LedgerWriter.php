<?php
// app/Services/LedgerWriter.php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Pricing\PriceFloor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one home for the three idempotent ledger writes — customer, sale, payment.
 * Both the REST controllers and the offline sync push call these, so the
 * idempotency, price-snapshot and total rules cannot drift between the online
 * and offline paths. Every method:
 *
 *  - is idempotent by (business_id, uuid): a replay returns the existing row;
 *  - stamps business_id via BelongsToTenant (app('tenant.id')) and created_by
 *    from app('tenant.user_id') — never from the caller's payload;
 *  - returns [model, bool $created] so the caller maps 201/applied vs 200/duplicate.
 *
 * Input is expected to be already validated against the matching rules*() set.
 */
class LedgerWriter
{
    public function __construct(private readonly KhataService $khata) {}

    /** @return array<string, array<int, mixed>> */
    public static function rulesForCustomer(): array
    {
        return [
            'uuid' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'village' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rulesForSale(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'customer_id' => ['required', 'uuid'],
            'sale_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_pack_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'integer', 'not_in:0'], // negative = return
            // Optional: omitted means "use the shop's default", which keeps the
            // REST endpoint and any older client working unchanged.
            'lines.*.rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rulesForPayment(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'customer_id' => ['required', 'uuid'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(['cash', 'upi', 'cheque', 'bank', 'other'])],
        ];
    }

    /** @return array{0: Customer, 1: bool} */
    public function createCustomer(array $data): array
    {
        // The web app has no outbox, so it lets the server mint the uuid.
        $uuid = $data['uuid'] ?? (string) Str::uuid();

        $existing = Customer::where('uuid', $uuid)->first();
        if ($existing) {
            return [$existing, false];
        }

        $customer = Customer::create([
            'uuid' => $uuid,
            'name' => $data['name'],
            'village' => $data['village'] ?? null,
            'phone' => $data['phone'] ?? null,
            'opening_balance' => $data['opening_balance'] ?? '0.00',
        ]);

        return [$customer, true];
    }

    /** @return array{0: Sale, 1: bool} */
    public function createSale(array $data): array
    {
        $existing = Sale::where('uuid', $data['uuid'])->first();
        if ($existing) {
            return [$existing->load('lines'), false];
        }

        // findOrFail under RLS: a cross-tenant customer/pack is invisible → 404.
        $customer = Customer::findOrFail($data['customer_id']);

        $sale = DB::transaction(function () use ($data, $customer) {
            // Batch-fetch every pack (with product/packSize eager-loaded, per
            // PriceFloor::for()'s docblock) in ONE query before the loop, instead
            // of one findOrFail per line — a 6-line sale would otherwise be ~18
            // queries (findOrFail + product + packSize per line) instead of 1.
            // RLS makes a cross-tenant pack invisible to whereIn() exactly as it
            // was to findOrFail(), so the 404-on-foreign-pack behaviour is unchanged.
            $packIds = array_column($data['lines'], 'product_pack_id');
            $packs = ProductPack::with(['product', 'packSize'])->whereIn('id', $packIds)->get()->keyBy('id');

            // Freeze rates and total first so the sale saves once (version stays 1).
            $lines = [];
            $total = '0.00';
            foreach ($data['lines'] as $line) {
                $pack = $packs[$line['product_pack_id']] ?? null;
                if ($pack === null) {
                    throw (new ModelNotFoundException)->setModel(ProductPack::class, [$line['product_pack_id']]);
                }

                // list_rate is ALWAYS the server's own answer. Accepting it from
                // the client would let a phone claim it sold at list while
                // charging less, making the discount record fiction.
                $listRate = $this->khata->snapshotRate($pack);
                $rate = isset($line['rate']) ? bcadd((string) $line['rate'], '0', 2) : $listRate;

                // The cost basis, snapshot rather than enforced.
                //
                // This used to throw below cost. It no longer does: this shop
                // genuinely sells some packs at or under cost, and refusing
                // meant half its catalog could not be sold at all. The floor is
                // now advice the client shows and the salesman confirms — see
                // the phone's below-cost confirmation in Forms.jsx.
                //
                // Recorded on the line because "what did we sell under cost?"
                // cannot be answered later from today's cost, for the same
                // reason list_rate is snapshot: costs move.
                $floor = PriceFloor::for($pack);

                $lineTotal = bcmul($rate, (string) $line['qty'], 2);

                $lines[] = [
                    'product_pack_id' => $pack->id,
                    'qty' => $line['qty'],
                    'rate' => $rate,
                    'list_rate' => $listRate,
                    'cost_at_sale' => $floor,
                    'line_total' => $lineTotal,
                ];
                $total = bcadd($total, $lineTotal, 2);
            }

            $sale = new Sale([
                'business_id' => app('tenant.id'),
                'uuid' => $data['uuid'],
                'customer_id' => $customer->id,
                'sale_date' => $data['sale_date'],
            ]);
            $sale->created_by = app('tenant.user_id');
            $sale->total = $total;
            $sale->save();

            foreach ($lines as $l) {
                $saleLine = new SaleLine([
                    'business_id' => app('tenant.id'),
                    'sale_id' => $sale->id,
                    'product_pack_id' => $l['product_pack_id'],
                    'qty' => $l['qty'],
                    'rate' => $l['rate'],
                ]);
                $saleLine->list_rate = $l['list_rate'];
                // Server-authored like list_rate, and for the same reason: a
                // phone must not be able to claim it sold above a cost it made
                // up. Stamped, never fillable.
                $saleLine->cost_at_sale = $l['cost_at_sale'];
                $saleLine->line_total = $l['line_total'];
                $saleLine->save();
            }

            return $sale;
        });

        return [$sale->load('lines'), true];
    }

    /** @return array{0: Payment, 1: bool} */
    public function recordPayment(array $data): array
    {
        $existing = Payment::where('uuid', $data['uuid'])->first();
        if ($existing) {
            return [$existing, false];
        }

        $customer = Customer::findOrFail($data['customer_id']);

        $payment = new Payment([
            'business_id' => app('tenant.id'),
            'uuid' => $data['uuid'],
            'customer_id' => $customer->id,
            'payment_date' => $data['payment_date'],
            'amount' => $data['amount'],
            'mode' => $data['mode'],
        ]);
        $payment->created_by = app('tenant.user_id');
        $payment->save();

        return [$payment, true];
    }
}
