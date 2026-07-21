<?php
// app/Http/Controllers/Api/V1/KhataController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\KhataService;
use Illuminate\Http\Request;

class KhataController extends Controller
{
    /**
     * The "who owes me" screen: every customer with its outstanding. Readable by
     * every role — a salesman and an accountant both need the khata, so no policy
     * gate here (reads are ungated, exactly like GET /catalog).
     */
    public function index(Request $request, KhataService $khata)
    {
        $includeArchived = $request->boolean('include_archived');

        $customers = Customer::query()
            ->unless($includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('name')
            ->get();

        return response()->json([
            'customers' => $customers->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'village' => $c->village,
                'phone' => $c->phone,
                'opening_balance' => $c->opening_balance,
                'outstanding' => $khata->outstandingFor($c),
                'archived_at' => $c->archived_at,
                'version' => $c->version,
            ])->values(),
        ]);
    }

    /**
     * One customer's khata: the time-ordered statement with a running balance,
     * plus the final outstanding. findOrFail under RLS → a cross-tenant id 404s.
     */
    public function show(string $id, KhataService $khata)
    {
        $customer = Customer::findOrFail($id);
        $ledger = $khata->ledgerFor($customer);

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'village' => $customer->village,
                'phone' => $customer->phone,
                'opening_balance' => $customer->opening_balance,
            ],
            'outstanding' => $khata->outstandingFor($customer),
            'ledger' => $ledger->map(fn ($e) => [
                'kind' => $e['kind'],
                'id' => $e['ref']->id,
                'date' => $e['date'],
                'delta' => $e['delta'],
                'running_balance' => $e['running_balance'],
            ])->values(),
        ]);
    }
}
