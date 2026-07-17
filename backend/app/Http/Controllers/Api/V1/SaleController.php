<?php
// app/Http/Controllers/Api/V1/SaleController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Policies\KhataPolicy;
use App\Services\KhataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaleController extends Controller
{
    public function store(Request $request, KhataService $khata)
    {
        if (! (new KhataPolicy())->recordSale()) {
            return $this->denied();
        }

        $data = $request->validate([
            'uuid' => ['required', 'uuid'], // client-generated: the idempotency key
            'customer_id' => ['required', 'uuid'],
            'sale_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_pack_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'integer', 'not_in:0'], // negative = return
        ]);

        // Idempotent replay: a retry over a flaky link must not double-post. RLS
        // has already scoped sales to this tenant, so a uuid match is this tenant's.
        $existing = Sale::where('uuid', $data['uuid'])->first();
        if ($existing) {
            return response()->json($existing->load('lines'), 200);
        }

        // findOrFail under RLS: a customer from another tenant is invisible, so a
        // cross-tenant id 404s rather than leaking existence.
        $customer = Customer::findOrFail($data['customer_id']);

        $sale = DB::transaction(function () use ($data, $customer, $khata) {
            // Resolve packs and freeze rates first, so the sale's total is known
            // before its single insert — a second save would bump HasVersion on a
            // brand-new row (see the plan's Task 3 note).
            $lines = [];
            $total = '0.00';
            foreach ($data['lines'] as $line) {
                $pack = ProductPack::findOrFail($line['product_pack_id']);
                $rate = $khata->snapshotRate($pack); // frozen now, never read live
                $lineTotal = bcmul($rate, (string) $line['qty'], 2);

                $lines[] = [
                    'product_pack_id' => $pack->id,
                    'qty' => $line['qty'],
                    'rate' => $rate,
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
            $sale->created_by = app('tenant.user_id'); // not fillable
            $sale->total = $total;                      // total = Σ line_total
            $sale->save();

            foreach ($lines as $l) {
                $saleLine = new SaleLine([
                    'business_id' => app('tenant.id'),
                    'sale_id' => $sale->id,
                    'product_pack_id' => $l['product_pack_id'],
                    'qty' => $l['qty'],
                    'rate' => $l['rate'],
                ]);
                $saleLine->line_total = $l['line_total']; // not fillable
                $saleLine->save();
            }

            return $sale;
        });

        return response()->json($sale->load('lines'), 201);
    }

    public function void(string $id)
    {
        if (! (new KhataPolicy())->voidSale()) {
            return $this->denied();
        }

        $original = Sale::with('lines')->findOrFail($id);

        if ($original->reverses_id) {
            return response()->json(['message' => 'Cannot void a reversal.'], 422);
        }
        if (Sale::where('reverses_id', $original->id)->exists()) {
            return response()->json(['message' => 'Sale is already voided.'], 409);
        }

        // A void writes a NEW sale with negated lines and total, pointing back at
        // the original. The original stays byte-for-byte intact; outstanding nets
        // to the pre-sale value because the reversal's total cancels it.
        $reversal = DB::transaction(function () use ($original) {
            $reversal = new Sale([
                'business_id' => app('tenant.id'),
                'uuid' => (string) Str::uuid(), // a void has no client uuid
                'customer_id' => $original->customer_id,
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
                $r->line_total = bcmul((string) $line->line_total, '-1', 2);
                $r->save();
            }

            return $reversal;
        });

        return response()->json($reversal->load('lines'), 201);
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'You do not have permission for this action.'],
            403
        );
    }
}
