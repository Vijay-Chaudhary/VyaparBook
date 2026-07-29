<?php
// app/Http/Controllers/Web/CustomerController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Ledger\LedgerReverser;
use App\Ledger\ReversalNotAllowed;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\DashboardReportService;
use App\Services\KhataService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer master, for the owner console: Blade, online-only, owner-only.
 * The mirror of SupplierController on the sell side, and the destination every
 * console list that names a customer links to — the overdue review, the orders
 * queue, the invoice queue, the dashboard's outstanding table.
 *
 * Who a customer IS can be maintained here; what they OWE cannot. Sales and
 * payments stay in the offline app, where recording them works without a
 * connection, and duplicating that here would give the same money two write
 * paths. So this controller writes only to the customer row itself, and the
 * khata below it stays read-only.
 *
 * Deleting archives rather than removes: a customer carrying sales or payments
 * cannot vanish without orphaning those rows and silently restating history.
 * Outstanding must stay recomputable from the ledger (PRD §9), so the row
 * survives and `restore` puts it back.
 *
 * Same owner-tool pattern as SupplierController — the OWNED business comes from
 * the caller's membership, never the request, and the work runs tenant-pinned.
 */
class CustomerController extends Controller
{
    use ResolvesOwnedTenant;

    public function __construct(
        private readonly KhataService $khata,
        private readonly DashboardReportService $dashboard,
        private readonly LedgerReverser $reverser,
    ) {}

    /** Everyone on the book with what they owe, biggest first, plus the add form. */
    public function index(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        [$summary, $archived] = $this->runInTenant($businessId, fn () => [
            // Reuses the dashboard's single-query identity rather than looping
            // KhataService per customer — the totals agree by construction.
            $this->dashboard->customerOutstanding($businessId),
            Customer::where('business_id', $businessId)
                ->whereNotNull('archived_at')
                ->orderBy('name')
                ->get(),
        ]);

        return view('customers.index', [
            'businessId' => $businessId,
            'summary' => $summary,
            'archived' => $archived,
        ]);
    }

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

        if ($found === null) {
            return redirect()->route('customers', ['business' => $businessId]);
        }

        [$row, $ledger, $outstanding] = $found;

        return view('customers.show', [
            'businessId' => $businessId,
            'customer' => $row,
            'ledger' => $ledger,
            'outstanding' => $outstanding,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate($this->rules());

        // Minted here when the form does not carry one: every customer row needs
        // a uuid, because the offline app addresses them by it, not by id.
        $uuid = $request->input('uuid') ?: (string) Str::uuid();

        $this->runInTenant($businessId, function () use ($data, $uuid) {
            if (Customer::where('uuid', $uuid)->exists()) {
                return; // idempotent replay — a double submit adds one row
            }

            // business_id via BelongsToTenant. Name is deliberately not unique:
            // two villages really do have their own Santosh Singh.
            Customer::create([
                'uuid' => $uuid,
                'name' => $data['name'],
                'village' => $data['village'] ?? null,
                'phone' => $data['phone'] ?? null,
                'opening_balance' => bcadd((string) ($data['opening_balance'] ?? '0'), '0', 2),
            ]);
        });

        return redirect()->route('customers', ['business' => $businessId]);
    }

    public function update(Request $request, string $customer): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate($this->rules(forUpdate: true));

        $this->runInTenant($businessId, function () use ($businessId, $customer, $data) {
            $row = Customer::where('business_id', $businessId)->find($customer);
            if ($row === null) {
                return;
            }

            $row->name = $data['name'];
            $row->village = $data['village'] ?? null;
            $row->phone = $data['phone'] ?? null;

            // Only when actually submitted. The opening balance is the pre-app
            // history, so an absent field must leave it alone rather than reset
            // it to zero and quietly restate what the customer owes.
            if (array_key_exists('opening_balance', $data)) {
                $row->opening_balance = bcadd((string) $data['opening_balance'], '0', 2);
            }

            $row->save();
        });

        return redirect()->route('customers.show', ['customer' => $customer, 'business' => $businessId]);
    }

    /**
     * Archive, never delete. The row carries sales and payments; removing it
     * would orphan them and change every historical total that counted them.
     */
    public function destroy(Request $request, string $customer): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $this->runInTenant($businessId, function () use ($businessId, $customer) {
            $row = Customer::where('business_id', $businessId)->find($customer);
            if ($row === null) {
                return;
            }

            // archived_at is not fillable, so it is assigned directly.
            $row->archived_at = Carbon::now();
            $row->save();
        });

        return redirect()->route('customers', ['business' => $businessId]);
    }

    public function restore(Request $request, string $customer): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $this->runInTenant($businessId, function () use ($businessId, $customer) {
            $row = Customer::where('business_id', $businessId)->find($customer);
            if ($row === null) {
                return;
            }

            $row->archived_at = null;
            $row->save();
        });

        return redirect()->route('customers', ['business' => $businessId]);
    }

    /**
     * Mirrors LedgerWriter::rulesForCustomer, so the console and the offline app
     * accept exactly the same customer.
     *
     * @return array<string, array<int, string>>
     */
    private function rules(bool $forUpdate = false): array
    {
        return [
            'uuid' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'village' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20'],
            'opening_balance' => [$forUpdate ? 'sometimes' : 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Void a sale from the owner's ledger.
     *
     * Append-only: LedgerReverser writes a mirror-image sale rather than
     * removing anything, so outstanding, cash flow, COGS and any issued invoice
     * all stay consistent with what is on the books. The ledger then reads
     * "sale ₹500, voided ₹500" instead of a gap where a sale used to be.
     */
    public function voidSale(Request $request, string $customer, string $sale): RedirectResponse
    {
        return $this->correct($request, $customer, function () use ($sale) {
            $row = Sale::with('lines')->find($sale);

            if ($row === null) {
                throw new NotFoundHttpException;
            }

            $this->reverser->voidSale($row);

            return __('customers.voided');
        });
    }

    /** Reverse a payment. Outstanding rises back by the reversed amount. */
    public function reversePayment(Request $request, string $customer, string $payment): RedirectResponse
    {
        return $this->correct($request, $customer, function () use ($payment) {
            $row = Payment::find($payment);

            if ($row === null) {
                throw new NotFoundHttpException;
            }

            $this->reverser->reversePayment($row);

            return __('customers.reversed');
        });
    }

    /**
     * Shared shape for both corrections: resolve the owned tenant, run pinned,
     * turn a refusal into a readable message rather than a 500.
     */
    private function correct(Request $request, string $customer, callable $work): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $result = $this->runInTenant($businessId, function () use ($businessId, $customer, $work) {
            // Explicit owner scope on top of RLS, as everywhere else here.
            if (Customer::where('business_id', $businessId)->find($customer) === null) {
                throw new NotFoundHttpException;
            }

            try {
                return [true, $work()];
            } catch (ReversalNotAllowed $e) {
                return [false, $e->getMessage()];
            }
        });

        [$ok, $message] = $result;

        return redirect()
            ->route('customers.show', ['customer' => $customer, 'business' => $businessId])
            ->with($ok ? 'status' : 'error', $message);
    }
}
