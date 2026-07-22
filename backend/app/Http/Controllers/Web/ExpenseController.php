<?php
// app/Http/Controllers/Web/ExpenseController.php

namespace App\Http\Controllers\Web;

use App\Expenses\ExpenseCategory;
use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Reports\ReportPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Operating-expenses entry (Phase 1): Blade, online-only, owner-only. Same
 * owner-tool pattern as BillingController/ReportController — the caller's OWNED
 * business is resolved from their membership (never the request), and work runs
 * tenant-pinned (RLS + app scope + owner). Not behind the write plan-gate: a
 * lapsed owner still records their own bookkeeping, like the billing page.
 */
class ExpenseController extends Controller
{
    use ResolvesOwnedTenant;

    /** The selected month's expenses + total + add form. */
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

        [$expenses, $total] = $this->runInTenant($businessId, function () use ($period) {
            $rows = Expense::query()
                ->whereNull('archived_at')
                ->whereRaw('extract(year from spent_on) = ?', [$period->year])
                ->whereRaw('extract(month from spent_on) = ?', [$period->month])
                ->orderByDesc('spent_on')
                ->get();

            $total = $rows->reduce(fn (string $c, Expense $e) => bcadd($c, (string) $e->amount, 2), '0.00');

            return [$rows, $total];
        });

        return view('expenses.index', [
            'businessId' => $businessId,
            'period' => $period,
            'expenses' => $expenses,
            'total' => $total,
            'categories' => ExpenseCategory::keys(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $this->validated($request);
        $uuid = $request->input('uuid') ?: (string) Str::uuid();

        $this->runInTenant($businessId, function () use ($data, $uuid) {
            if (Expense::where('uuid', $uuid)->exists()) {
                return; // idempotent replay — do not append a second row
            }
            $expense = new Expense([
                'uuid' => $uuid,
                'category' => $data['category'],
                'amount' => bcadd((string) $data['amount'], '0', 2),
                'spent_on' => $data['spent_on'],
                'note' => $data['note'] ?? null,
            ]);
            // business_id via BelongsToTenant, created_by from the tenant context.
            $expense->created_by = app('tenant.user_id');
            $expense->save();
        });

        return redirect()->route('expenses', $this->periodQuery($request, $businessId));
    }

    public function update(Request $request, string $expense): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $this->validated($request);

        $this->runInTenant($businessId, function () use ($businessId, $expense, $data) {
            // Explicit owner scope — never trust the id alone.
            $row = Expense::where('business_id', $businessId)->whereNull('archived_at')->find($expense);
            $row?->update([
                'category' => $data['category'],
                'amount' => bcadd((string) $data['amount'], '0', 2),
                'spent_on' => $data['spent_on'],
                'note' => $data['note'] ?? null,
            ]);
        });

        return redirect()->route('expenses', $this->periodQuery($request, $businessId));
    }

    public function destroy(Request $request, string $expense): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $this->runInTenant($businessId, function () use ($businessId, $expense) {
            $row = Expense::where('business_id', $businessId)->whereNull('archived_at')->find($expense);
            // archived_at is not fillable (soft-delete is not request-driven), so
            // set it directly rather than via a mass-assignment update().
            if ($row !== null) {
                $row->archived_at = now();
                $row->save();
            }
        });

        return redirect()->route('expenses', $this->periodQuery($request, $businessId));
    }

    /** @return array{category: string, amount: string, spent_on: string, note: ?string} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(ExpenseCategory::keys())],
            'amount' => ['required', 'numeric', 'gt:0'],
            'spent_on' => ['required', 'date'],
            'note' => [
                Rule::requiredIf(fn () => ExpenseCategory::requiresNote((string) $request->input('category'))),
                'nullable', 'string', 'max:255',
            ],
        ]);
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
