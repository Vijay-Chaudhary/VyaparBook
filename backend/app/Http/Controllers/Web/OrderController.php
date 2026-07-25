<?php
// app/Http/Controllers/Web/OrderController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductPack;
use App\Orders\OrderStatus;
use App\Pricing\PriceFloor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accepting orders (PRD Phase: order workflow): Blade, online-only, owner/admin.
 *
 * Acceptance is deliberately the ONLY online step in the workflow — which is
 * what makes it the sync boundary: a salesman cannot pack until their phone has
 * pulled the decision.
 */
class OrderController extends Controller
{
    use ResolvesOwnedTenant;

    /** Accepting is a manager's job, so admins qualify as well as owners. */
    private const ROLES = ['owner', 'admin'];

    public function index(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'), self::ROLES);
        if ($businessId === null) {
            return redirect()->route('app');
        }

        [$pending, $recent] = $this->runInTenant($businessId, fn () => [
            Order::query()->where('status', OrderStatus::PENDING)
                ->with(['customer', 'lines.productPack.product', 'lines.productPack.packSize'])
                ->orderBy('order_date')->get(),
            Order::query()->whereNot('status', OrderStatus::PENDING)
                ->with('customer')->orderByDesc('updated_at')->limit(50)->get(),
        ]);

        return view('orders.index', [
            'businessId' => $businessId,
            'pending' => $pending,
            'recent' => $recent,
        ]);
    }

    public function accept(Request $request, string $order): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'), self::ROLES);
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.qty' => ['required_with:lines', 'integer', 'not_in:0'],
            'lines.*.rate' => ['required_with:lines', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $error = $this->runInTenant($businessId, function () use ($businessId, $order, $data) {
            $model = Order::query()->where('business_id', $businessId)->with('lines')->find($order);

            if ($model === null) {
                throw new NotFoundHttpException;
            }

            if (! OrderStatus::canTransition($model->status, OrderStatus::ACCEPTED)) {
                return __('orders.not_pending');
            }

            $total = '0.00';

            foreach ($model->lines as $line) {
                $edit = $data['lines'][$line->id] ?? null;
                $qty = $edit ? (int) $edit['qty'] : $line->qty;
                $rate = $edit ? bcadd((string) $edit['rate'], '0', 2) : (string) $line->rate;

                // The same floor the phone and LedgerWriter enforce. An edit
                // below cost must not sneak in through the accept screen.
                $pack = ProductPack::with(['product', 'packSize'])->find($line->product_pack_id);
                $floor = $pack ? PriceFloor::for($pack) : null;

                if ($floor !== null && bccomp($rate, $floor, 2) < 0) {
                    // Refuse the WHOLE acceptance: a half-applied edit would
                    // leave the shop promised one thing and billed another.
                    return __('sales.rate_below_floor', [
                        'product' => $pack->product?->name_en ?: $pack->product?->name_hi ?: 'this product',
                        'floor' => $floor,
                    ]);
                }

                $lineTotal = bcmul($rate, (string) $qty, 2);
                $total = bcadd($total, $lineTotal, 2);

                $line->qty = $qty;
                $line->rate = $rate;
                $line->line_total = $lineTotal;
                $line->save();
            }

            $model->status = OrderStatus::ACCEPTED;
            $model->accepted_by = (int) auth()->id();
            $model->accepted_at = Carbon::now();
            $model->total = $total;
            $model->save();

            return null;
        });

        return redirect()->route('orders', ['business' => $businessId])
            ->with($error === null ? 'status' : 'error', $error ?? __('orders.accepted'));
    }

    public function reject(Request $request, string $order): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'), self::ROLES);
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate(['status_note' => ['nullable', 'string', 'max:255']]);

        $this->runInTenant($businessId, function () use ($businessId, $order, $data) {
            $model = Order::query()->where('business_id', $businessId)->find($order);

            if ($model === null) {
                throw new NotFoundHttpException;
            }

            if (! OrderStatus::canTransition($model->status, OrderStatus::REJECTED)) {
                return;
            }

            $model->status = OrderStatus::REJECTED;
            $model->status_note = $data['status_note'] ?? null;
            $model->save();
        });

        return redirect()->route('orders', ['business' => $businessId])->with('status', __('orders.rejected'));
    }
}
