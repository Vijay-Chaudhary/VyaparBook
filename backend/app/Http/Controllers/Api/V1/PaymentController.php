<?php
// app/Http/Controllers/Api/V1/PaymentController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Policies\KhataPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function store(Request $request)
    {
        if (! (new KhataPolicy())->recordPayment()) {
            return $this->denied();
        }

        $data = $request->validate([
            'uuid' => ['required', 'uuid'], // client-generated: the idempotency key
            'customer_id' => ['required', 'uuid'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(['cash', 'upi', 'cheque', 'bank', 'other'])],
        ]);

        // Idempotent replay: a retry over a flaky link must not double-credit.
        $existing = Payment::where('uuid', $data['uuid'])->first();
        if ($existing) {
            return response()->json($existing, 200);
        }

        // findOrFail under RLS: a cross-tenant customer id 404s, never leaks.
        $customer = Customer::findOrFail($data['customer_id']);

        $payment = new Payment([
            'business_id' => app('tenant.id'),
            'uuid' => $data['uuid'],
            'customer_id' => $customer->id,
            'payment_date' => $data['payment_date'],
            'amount' => $data['amount'],
            'mode' => $data['mode'],
        ]);
        $payment->created_by = app('tenant.user_id'); // not fillable
        $payment->save();

        return response()->json($payment, 201);
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
