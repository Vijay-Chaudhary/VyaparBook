<?php
// app/Http/Controllers/Api/V1/PaymentController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Ledger\LedgerReverser;
use App\Ledger\ReversalNotAllowed;
use App\Models\Payment;
use App\Policies\KhataPolicy;
use App\Services\LedgerWriter;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly LedgerReverser $reverser) {}

    public function store(Request $request, LedgerWriter $writer)
    {
        if (! (new KhataPolicy())->recordPayment()) {
            return $this->denied();
        }

        $data = $request->validate(LedgerWriter::rulesForPayment());

        // Idempotent create through the shared writer — the same path sync uses.
        [$payment, $created] = $writer->recordPayment($data);

        return response()->json($payment, $created ? 201 : 200);
    }

    public function reverse(string $id)
    {
        // A payment reversal is a correction — a privileged act, like voiding a sale.
        if (! (new KhataPolicy())->voidSale()) {
            return $this->denied();
        }

        $original = Payment::findOrFail($id);

        try {
            $reversal = $this->reverser->reversePayment($original);
        } catch (ReversalNotAllowed $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $e->reason === ReversalNotAllowed::ALREADY_REVERSED ? 409 : 422
            );
        }

        return response()->json($reversal, 201);
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'You do not have permission for this action.'],
            403
        );
    }
}
