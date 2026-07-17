<?php
// app/Http/Controllers/Api/V1/CustomerController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Policies\KhataPolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function store(Request $request)
    {
        if (! (new KhataPolicy())->recordSale()) {
            return $this->denied();
        }

        $data = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'village' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Client sends its uuid when it created the row offline; the web app that
        // has no outbox lets the server mint one.
        $uuid = $data['uuid'] ?? (string) Str::uuid();

        // Idempotent create: a retried outbox mutation with the same uuid replays
        // the existing row rather than duplicating it. RLS has already scoped
        // customers to this tenant, so a uuid match is this tenant's row.
        $existing = Customer::where('uuid', $uuid)->first();
        if ($existing) {
            return response()->json($existing, 200);
        }

        // business_id is stamped by BelongsToTenant from app('tenant.id') — never
        // taken from the request, which is why it is not in the validated set.
        $customer = Customer::create([
            'uuid' => $uuid,
            'name' => $data['name'],
            'village' => $data['village'] ?? null,
            'phone' => $data['phone'] ?? null,
            'opening_balance' => $data['opening_balance'] ?? '0.00',
        ]);

        return response()->json($customer, 201);
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
