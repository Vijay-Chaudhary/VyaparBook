<?php
// app/Http/Controllers/Web/ReportController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Reports\ReportPeriod;
use App\Services\DashboardReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The owner management dashboard (Phase 0 spec): Blade, online-only, owner-only.
 * Read-only, so deliberately OUTSIDE any write plan-gate — a lapsed owner may
 * still view their reports, exactly like the billing page stays reachable.
 *
 * Owner resolution and tenant pinning come from ResolvesOwnedTenant, the same
 * pattern the billing and onboarding controllers use.
 */
class ReportController extends Controller
{
    use ResolvesOwnedTenant;

    public function __construct(private readonly DashboardReportService $reports) {}

    public function show(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));

        if ($businessId === null) {
            return redirect()->route('app');
        }

        $period = ReportPeriod::fromInput(
            $request->integer('year') ?: null,
            $request->integer('month') ?: null,
        );

        $report = $this->runInTenant(
            $businessId,
            fn () => $this->reports->forMonth($businessId, $period),
        );

        return view('reports.dashboard', [
            'report' => $report,
            'businessId' => $businessId,
        ]);
    }
}
