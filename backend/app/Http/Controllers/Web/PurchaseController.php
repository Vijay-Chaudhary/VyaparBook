<?php
// app/Http/Controllers/Web/PurchaseController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Reports\ReportPeriod;
use App\Services\PurchaseWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Costed raw-material purchases (Phase 2a): Blade, online-only, owner-only.
 * Same owner-tool pattern as ExpenseController — the caller's OWNED business is
 * resolved from their membership (never the request), and work runs tenant-pinned
 * (the tenant scope + owner). Not behind the write plan-gate: a lapsed owner still
 * records their own bookkeeping.
 *
 * The purchase and its costed stock-in are written by PurchaseWriter, so this
 * controller never touches stock_movements directly and deleting a purchase
 * reverses the stock-in through the same one place.
 */
class PurchaseController extends Controller
{
    use ResolvesOwnedTenant;

    public function __construct(private readonly PurchaseWriter $writer) {}

    /** The selected month's purchases + total, plus the record form. */
    public function index(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $period = ReportPeriod::fromInput(
            $request->integer('year') ?: null,
            $request->integer('month') ?: null,
        );

        [$purchases, $total, $suppliers, $materials] = $this->runInTenant($businessId, function () use ($period) {
            $rows = Purchase::query()
                ->with(['supplier', 'rawMaterial'])
                ->whereNull('archived_at')
                ->whereRaw('extract(year from purchase_date) = ?', [$period->year])
                ->whereRaw('extract(month from purchase_date) = ?', [$period->month])
                ->orderByDesc('purchase_date')
                ->get();

            $total = $rows->reduce(fn (string $c, Purchase $p) => bcadd($c, (string) $p->total, 2), '0.00');

            return [
                $rows,
                $total,
                Supplier::whereNull('archived_at')->orderBy('name')->get(),
                RawMaterial::whereNull('archived_at')->orderBy('name')->get(),
            ];
        });

        return view('purchases.index', [
            'businessId' => $businessId,
            'period' => $period,
            'purchases' => $purchases,
            'total' => $total,
            'suppliers' => $suppliers,
            'materials' => $materials,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            // uuid is client-supplied (idempotency key); validate it so a malformed
            // value is a clean error, not a raw QueryException from the uuid column.
            'uuid' => ['nullable', 'uuid'],
            'supplier_id' => ['required', 'uuid'],
            'raw_material_id' => ['required', 'uuid'],
            'purchase_date' => ['required', 'date'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->runInTenant($businessId, function () use ($data, $request) {
            // A cross-tenant supplier/material is invisible under the tenant scope, so the
            // writer's findOrFail turns a guessed id into a 404, not a write.
            $this->writer->record([
                'uuid' => $request->input('uuid') ?: (string) Str::uuid(),
                'supplier_id' => $data['supplier_id'],
                'raw_material_id' => $data['raw_material_id'],
                'purchase_date' => $data['purchase_date'],
                'qty' => (string) $data['qty'],
                'unit_cost' => (string) $data['unit_cost'],
                'note' => $data['note'] ?? null,
            ]);
        });

        return redirect()->route('purchases', $this->periodQuery($request, $businessId));
    }

    /** Archive the purchase and remove the stock-in it created. */
    public function destroy(Request $request, string $purchase): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $this->runInTenant($businessId, function () use ($businessId, $purchase) {
            // Explicit owner scope — never trust the id alone.
            $row = Purchase::where('business_id', $businessId)->whereNull('archived_at')->find($purchase);
            if ($row !== null) {
                $this->writer->remove($row);
            }
        });

        return redirect()->route('purchases', $this->periodQuery($request, $businessId));
    }

    /** Preserve business + period on the redirect back to the list. */
    private function periodQuery(Request $request, string $businessId): array
    {
        return array_filter([
            'business' => $businessId,
            'year' => $request->integer('year') ?: null,
            'month' => $request->integer('month') ?: null,
        ]);
    }
}
