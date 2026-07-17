<?php
// app/Http/Controllers/Api/V1/CustomerController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Policies\KhataPolicy;
use App\Services\LedgerWriter;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function store(Request $request, LedgerWriter $writer)
    {
        if (! (new KhataPolicy())->recordSale()) {
            return $this->denied();
        }

        $data = $request->validate(LedgerWriter::rulesForCustomer());

        // Idempotent create through the shared writer: a retried outbox mutation
        // with the same uuid replays the existing row (200) instead of creating
        // a duplicate (201). The online and offline paths share this one code path.
        [$customer, $created] = $writer->createCustomer($data);

        return response()->json($customer, $created ? 201 : 200);
    }

    public function update(Request $request, string $id)
    {
        if (! (new KhataPolicy())->recordSale()) {
            return $this->denied();
        }

        // findOrFail under RLS: another tenant's customer is invisible, so this
        // 404s rather than leaking existence with a 403.
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'village' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20'],
            'opening_balance' => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);

        $customer->update($data);

        return response()->json($customer->fresh());
    }

    public function destroy(string $id)
    {
        if (! (new KhataPolicy())->recordSale()) {
            return $this->denied();
        }

        // Archive, never delete: the khata references this customer for years.
        // archived_at is not fillable, so it is assigned directly.
        $customer = Customer::findOrFail($id);
        $customer->archived_at = Carbon::now();
        $customer->save();

        return response()->json(['message' => 'Archived.']);
    }

    public function restore(string $id)
    {
        if (! (new KhataPolicy())->recordSale()) {
            return $this->denied();
        }

        $customer = Customer::findOrFail($id);
        $customer->archived_at = null;
        $customer->save();

        return response()->json($customer->fresh());
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners, admins and salesmen can manage customers.'],
            403
        );
    }
}
