<?php
// app/Http/Controllers/Web/SupplierController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Suppliers and what we owe them (Phase 2a): Blade, online-only, owner-only.
 * The mirror of the customer khata, on the buy side. Same owner-tool pattern as
 * ExpenseController/PurchaseController — OWNED business from the membership,
 * tenant-pinned work, not behind the write plan-gate.
 *
 * Supplier payments deliberately do NOT touch cash (Phase 3); they only reduce
 * supplier outstanding, exactly as expenses do not touch cash.
 */
class SupplierController extends Controller
{
    use ResolvesOwnedTenant;

    /** Payment modes the column accepts (supplier_payments.mode, varchar 20). */
    private const MODES = ['cash', 'upi', 'bank', 'other'];

    public function __construct(private readonly SupplierService $suppliers) {}

    /** All suppliers with outstanding, highest first, plus the add form. */
    public function index(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $summary = $this->runInTenant(
            $businessId,
            fn () => $this->suppliers->outstandingSummary($businessId),
        );

        return view('suppliers.index', [
            'businessId' => $businessId,
            'summary' => $summary,
        ]);
    }

    /** One supplier's payables ledger + the record-payment form. */
    public function show(Request $request, string $supplier): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $found = $this->runInTenant($businessId, function () use ($businessId, $supplier) {
            // Explicit owner scope on top of the tenant scope — never trust the id alone.
            $row = Supplier::where('business_id', $businessId)->find($supplier);
            if ($row === null) {
                return null;
            }

            return [$row, $this->suppliers->ledgerFor($row), $this->suppliers->outstandingFor($row)];
        });

        if ($found === null) {
            return redirect()->route('suppliers', ['business' => $businessId]);
        }

        [$row, $ledger, $outstanding] = $found;

        return view('suppliers.show', [
            'businessId' => $businessId,
            'supplier' => $row,
            'ledger' => $ledger,
            'outstanding' => $outstanding,
            'modes' => self::MODES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'village' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            // Opening balance may be negative: an advance already paid.
            'opening_balance' => ['nullable', 'numeric'],
        ]);

        $uuid = $request->input('uuid') ?: (string) Str::uuid();

        $this->runInTenant($businessId, function () use ($data, $uuid) {
            if (Supplier::where('uuid', $uuid)->exists()) {
                return; // idempotent replay — do not append a second row
            }
            // business_id via BelongsToTenant.
            Supplier::create([
                'uuid' => $uuid,
                'name' => $data['name'],
                'village' => $data['village'] ?? null,
                'phone' => $data['phone'] ?? null,
                'opening_balance' => bcadd((string) ($data['opening_balance'] ?? '0'), '0', 2),
            ]);
        });

        return redirect()->route('suppliers', ['business' => $businessId]);
    }

    /** Record a payment to a supplier — reduces outstanding, does not touch cash. */
    public function storePayment(Request $request, string $supplier): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'mode' => ['required', Rule::in(self::MODES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $uuid = $request->input('uuid') ?: (string) Str::uuid();

        $this->runInTenant($businessId, function () use ($businessId, $supplier, $data, $uuid) {
            $row = Supplier::where('business_id', $businessId)->whereNull('archived_at')->find($supplier);
            if ($row === null || SupplierPayment::where('uuid', $uuid)->exists()) {
                return; // unknown supplier, or an idempotent replay
            }

            $payment = new SupplierPayment([
                'uuid' => $uuid,
                'supplier_id' => $row->id,
                'payment_date' => $data['payment_date'],
                'amount' => bcadd((string) $data['amount'], '0', 2),
                'mode' => $data['mode'],
                'note' => $data['note'] ?? null,
            ]);
            $payment->created_by = app('tenant.user_id');
            $payment->save();
        });

        return redirect()->route('suppliers.show', ['supplier' => $supplier, 'business' => $businessId]);
    }
}
