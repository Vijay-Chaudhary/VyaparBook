<?php
// app/Http/Controllers/Web/CustomerController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\KhataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One customer's khata, for the owner console: Blade, online-only, owner-only.
 * The mirror of SupplierController::show on the sell side, and the destination
 * every console list that names a customer links to — the overdue review, the
 * orders queue, the invoice queue, the dashboard's outstanding table. Before it
 * existed those screens could show what a customer owed but not why.
 *
 * Read-only by design. Recording sales and payments stays in the offline app,
 * where it can happen without a connection; duplicating it here would give the
 * same money two write paths.
 *
 * Same owner-tool pattern as SupplierController — the OWNED business comes from
 * the caller's membership, never the request, and the work runs tenant-pinned.
 */
class CustomerController extends Controller
{
    use ResolvesOwnedTenant;

    public function __construct(private readonly KhataService $khata) {}

    public function show(Request $request, string $customer): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $found = $this->runInTenant($businessId, function () use ($businessId, $customer) {
            // Explicit owner scope on top of RLS — never trust the id alone.
            $row = Customer::where('business_id', $businessId)->find($customer);
            if ($row === null) {
                return null;
            }

            return [$row, $this->khata->ledgerFor($row), $this->khata->outstandingFor($row)];
        });

        // The console has no customer index to fall back to, so an unknown id
        // lands on the dashboard the lists were reached from.
        if ($found === null) {
            return redirect()->route('reports.dashboard', ['business' => $businessId]);
        }

        [$row, $ledger, $outstanding] = $found;

        return view('customers.show', [
            'businessId' => $businessId,
            'customer' => $row,
            'ledger' => $ledger,
            'outstanding' => $outstanding,
        ]);
    }
}
