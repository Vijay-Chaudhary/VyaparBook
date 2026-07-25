<?php
// app/Http/Controllers/Web/InvoiceController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Sale;
use App\Services\InvoiceIssuer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tax invoices (PRD Phase 3): Blade, online-only, owner-only — the same
 * owner-tool pattern as expenses and reminders.
 *
 * Online by necessity, not habit: issuing allocates a gapless invoice number,
 * which an offline device cannot safely do. Sales stay offline-first; only the
 * act of invoicing one requires a connection.
 */
class InvoiceController extends Controller
{
    use ResolvesOwnedTenant;

    /** Issued invoices, plus the sales still waiting for one. */
    public function index(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        [$business, $invoices, $uninvoiced] = $this->runInTenant($businessId, fn () => [
            Business::findOrFail($businessId),
            Invoice::query()->orderByDesc('seq')->limit(50)->get(),
            // Reversals and already-invoiced sales are not candidates.
            Sale::query()
                ->whereNull('reverses_id')
                ->whereNotIn('id', Invoice::query()->select('sale_id'))
                ->with('customer')
                ->orderByDesc('sale_date')
                ->limit(50)
                ->get(),
        ]);

        return view('invoices.index', [
            'businessId' => $businessId,
            'business' => $business,
            'invoices' => $invoices,
            'uninvoiced' => $uninvoiced,
        ]);
    }

    /** Issue an invoice for one sale. */
    public function store(Request $request, InvoiceIssuer $issuer): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'sale' => ['required', 'uuid'],
            // 15 characters, the GSTIN format. Optional: a retail buyer has none.
            'buyer_gstin' => ['nullable', 'string', 'size:15'],
        ]);

        try {
            $invoice = $this->runInTenant($businessId, function () use ($businessId, $data, $issuer) {
                // Resolved inside the pin, never by implicit binding.
                $exists = Sale::query()->where('business_id', $businessId)->find($data['sale']);

                if ($exists === null) {
                    throw new NotFoundHttpException;
                }

                return $issuer->issue($data['sale'], $data['buyer_gstin'] ?? null);
            });
        } catch (RuntimeException $e) {
            // Symfony's HttpException extends RuntimeException, so an unowned
            // sale's 404 would otherwise be swallowed here and answered as a
            // friendly redirect — leaking that the id exists at all.
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            // Genuine refusals are lawful outcomes (no GSTIN, already invoiced,
            // a void), so they surface as a message rather than a stack trace.
            return redirect()->route('invoices', ['business' => $businessId])
                ->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', ['invoice' => $invoice->id, 'business' => $businessId]);
    }

    /** The print view. */
    public function show(Request $request, string $invoice): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $model = $this->runInTenant($businessId, function () use ($businessId, $invoice) {
            $found = Invoice::query()->where('business_id', $businessId)->with('lines')->find($invoice);

            if ($found === null) {
                throw new NotFoundHttpException;
            }

            return $found;
        });

        return view('invoices.show', ['businessId' => $businessId, 'invoice' => $model]);
    }
}
