<?php
// app/Http/Controllers/Api/V1/SaleController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Ledger\LedgerReverser;
use App\Ledger\ReversalNotAllowed;
use App\Models\Sale;
use App\Policies\KhataPolicy;
use App\Services\LedgerWriter;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(private readonly LedgerReverser $reverser) {}

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

        // The reversal itself lives in LedgerReverser, shared with the owner's
        // Blade ledger. Only the HTTP mapping belongs here.
        try {
            $reversal = $this->reverser->voidSale($original);
        } catch (ReversalNotAllowed $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $e->reason === ReversalNotAllowed::ALREADY_REVERSED ? 409 : 422
            );
        }

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
