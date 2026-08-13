<?php
// app/Http/Controllers/Web/CustomerController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Ledger\EditNotAllowed;
use App\Ledger\LedgerEditor;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\DashboardReportService;
use App\Services\KhataService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer master, for the owner console: Blade, online-only, owner-only.
 * The mirror of SupplierController on the sell side, and the destination every
 * console list that names a customer links to — the overdue review, the orders
 * queue, the invoice queue, the dashboard's outstanding table.
 *
 * RECORDING sales and payments stays in the offline app, where it works without
 * a connection, and duplicating that here would give the same money two write
 * paths. CORRECTING one is this screen's job: the khata is where a wrong figure
 * is noticed, and the owner is the only person allowed to change it. So each
 * ledger row can be edited or deleted here (LedgerEditor), and a deleted row can
 * be restored — but none can be created.
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
        private readonly LedgerEditor $editor,
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
            // Explicit owner scope on top of the tenant scope — never trust the id alone.
            $row = Customer::where('business_id', $businessId)->find($customer);
            if ($row === null) {
                return null;
            }

            $ledger = $this->khata->ledgerFor($row);

            // What each sale actually contained, for the edit form. Loaded onto
            // the very rows the ledger already holds rather than fetched again:
            // these are the same model instances, so the view sees the lines
            // without a second pass over sales.
            (new EloquentCollection($ledger->where('kind', 'sale')->pluck('ref')->all()))
                ->load('lines.productPack.product', 'lines.productPack.packSize');

            return [
                $row,
                $ledger,
                $this->khata->outstandingFor($row),
                $this->deletedEntries($row),
            ];
        });

        if ($found === null) {
            return redirect()->route('customers', ['business' => $businessId]);
        }

        [$row, $ledger, $outstanding, $deleted] = $found;

        return view('customers.show', [
            'businessId' => $businessId,
            'customer' => $row,
            'ledger' => $ledger,
            'outstanding' => $outstanding,
            'deleted' => $deleted,
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
     * Correct a sale in place — its date, and each line's quantity and rate.
     *
     * Lines are edited, not added or removed; LedgerEditor::updateSale explains
     * why. The sale's total is recomputed from the lines and never accepted from
     * the form, exactly as it is when the sale is first written.
     */
    public function updateSale(Request $request, string $customer, string $sale): RedirectResponse
    {
        $data = $request->validate([
            'sale_date' => ['required', 'date'],
            'lines' => ['sometimes', 'array'],
            // Mirrors LedgerWriter::rulesForSale, so an edit cannot produce a
            // sale the original write would have rejected. Negative qty is a
            // return and stays legal; zero is not a line.
            'lines.*.qty' => ['required', 'integer', 'not_in:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        return $this->edit($request, $customer, function () use ($sale, $data) {
            $this->editor->updateSale($this->findSale($sale), $data);

            return __('customers.sale_updated');
        });
    }

    /** Take a sale off the khata. Soft — it moves to the deleted list and can come back. */
    public function destroySale(Request $request, string $customer, string $sale): RedirectResponse
    {
        return $this->edit($request, $customer, function () use ($sale) {
            $this->editor->deleteSale($this->findSale($sale));

            return __('customers.sale_deleted');
        });
    }

    public function restoreSale(Request $request, string $customer, string $sale): RedirectResponse
    {
        return $this->edit($request, $customer, function () use ($sale) {
            // withTrashed: the whole point is that the row is currently deleted,
            // so the default scope would hide the only row this can act on.
            $row = Sale::withTrashed()->find($sale);

            if ($row === null) {
                throw new NotFoundHttpException;
            }

            $this->editor->restoreSale($row);

            return __('customers.sale_restored');
        });
    }

    /** Correct a payment in place — amount, date or mode. */
    public function updatePayment(Request $request, string $customer, string $payment): RedirectResponse
    {
        $data = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(['cash', 'upi', 'cheque', 'bank', 'other'])],
        ]);

        return $this->edit($request, $customer, function () use ($payment, $data) {
            $this->editor->updatePayment($this->findPayment($payment), $data);

            return __('customers.payment_updated');
        });
    }

    /** Take a payment off the khata. Outstanding rises back by its amount. */
    public function destroyPayment(Request $request, string $customer, string $payment): RedirectResponse
    {
        return $this->edit($request, $customer, function () use ($payment) {
            $this->editor->deletePayment($this->findPayment($payment));

            return __('customers.payment_deleted');
        });
    }

    public function restorePayment(Request $request, string $customer, string $payment): RedirectResponse
    {
        return $this->edit($request, $customer, function () use ($payment) {
            $row = Payment::withTrashed()->find($payment);

            if ($row === null) {
                throw new NotFoundHttpException;
            }

            $this->editor->restorePayment($row);

            return __('customers.payment_restored');
        });
    }

    /** 404 rather than null, so a stray id can never fall through to a success message. */
    private function findSale(string $sale): Sale
    {
        $row = Sale::with('lines')->find($sale);

        if ($row === null) {
            throw new NotFoundHttpException;
        }

        return $row;
    }

    private function findPayment(string $payment): Payment
    {
        $row = Payment::find($payment);

        if ($row === null) {
            throw new NotFoundHttpException;
        }

        return $row;
    }

    /**
     * Every deleted row for this customer, newest deletion first.
     *
     * Shaped like a KhataService ledger entry so the view can render it with the
     * same columns, but deliberately NOT part of the ledger: a deleted sale is
     * not a line of the statement, it is a line of what the statement no longer
     * says. It carries no running balance for the same reason.
     *
     * @return Collection<int, array{kind: string, ref: Sale|Payment, date: mixed, delta: string}>
     */
    private function deletedEntries(Customer $customer): Collection
    {
        $sales = $customer->sales()->onlyTrashed()->get()->map(fn (Sale $s) => [
            'kind' => 'sale',
            'ref' => $s,
            'date' => $s->sale_date,
            'delta' => (string) $s->total,
        ]);

        $payments = $customer->payments()->onlyTrashed()->get()->map(fn (Payment $p) => [
            'kind' => 'payment',
            'ref' => $p,
            'date' => $p->payment_date,
            'delta' => bcmul((string) $p->amount, '-1', 2),
        ]);

        return $sales->concat($payments)
            ->sortByDesc(fn (array $e) => $e['ref']->deleted_at)
            ->values();
    }

    /**
     * Shared shape for every khata correction: resolve the owned tenant, run
     * pinned, turn a refusal into a readable message rather than a 500.
     */
    private function edit(Request $request, string $customer, callable $work): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $result = $this->runInTenant($businessId, function () use ($businessId, $customer, $work) {
            // Explicit owner scope on top of the tenant scope, as everywhere else here.
            if (Customer::where('business_id', $businessId)->find($customer) === null) {
                throw new NotFoundHttpException;
            }

            try {
                return [true, $work()];
            } catch (EditNotAllowed $e) {
                return [false, $e->getMessage()];
            }
        });

        [$ok, $message] = $result;

        return redirect()
            ->route('customers.show', ['customer' => $customer, 'business' => $businessId])
            ->with($ok ? 'status' : 'error', $message);
    }
}
