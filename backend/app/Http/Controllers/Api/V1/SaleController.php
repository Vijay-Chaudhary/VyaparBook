<?php
// app/Http/Controllers/Api/V1/SaleController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Policies\KhataPolicy;
use App\Services\LedgerWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaleController extends Controller
{
    public function store(Request $request, LedgerWriter $writer)
    {
        if (! (new KhataPolicy())->recordSale()) {
            return $this->denied();
        }

        $data = $request->validate(LedgerWriter::rulesForSale());

        // Idempotent create through the shared writer (snapshot rate, single-write
        // total, replay on duplicate uuid) — the same path the offline sync uses.
        [$sale, $created] = $writer->createSale($data);

        return response()->json($sale, $created ? 201 : 200);
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
