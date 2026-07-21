<?php
// app/Http/Controllers/Api/V1/PaymentController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Policies\KhataPolicy;
use App\Services\LedgerWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
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

        if ($original->reverses_id) {
            return response()->json(['message' => 'Cannot reverse a reversal.'], 422);
        }
        if (Payment::where('reverses_id', $original->id)->exists()) {
            return response()->json(['message' => 'Payment is already reversed.'], 409);
        }

        // Append-only: a NEW payment with the negated amount, pointing back at the
        // original. Outstanding rises back by the reversed amount; nothing mutated.
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
