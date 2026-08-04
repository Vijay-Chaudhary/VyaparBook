<?php
// app/Http/Controllers/Web/OrderController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Orders\OrderStatus;
use App\Services\OrderWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public function __construct(private readonly OrderWriter $writer) {}

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
            // Lines eager-loaded here too: a decided order that shows only a
            // total cannot answer "what did we actually agree to send them?".
            Order::query()->whereNot('status', OrderStatus::PENDING)
                ->with(['customer', 'lines.productPack.product', 'lines.productPack.packSize'])
                ->orderByDesc('updated_at')->limit(50)->get(),
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

    /**
     * Cancel an order the shop is not going to fulfil.
     *
     * A real cancel, not a reversal: an order before delivery is not money, so
     * there is nothing on the books to mirror. Delivery is the only step that
     * writes to the khata, and OrderStatus refuses to leave a terminal state,
     * so a delivered order cannot be cancelled here.
     *
     * The salesman could already cancel from the phone; the owner could not,
     * which left them watching an order they had decided against.
     */
    public function cancel(Request $request, string $order): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'), self::ROLES);
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate(['status_note' => ['nullable', 'string', 'max:255']]);

        $error = $this->runInTenant($businessId, function () use ($businessId, $order, $data) {
            $model = Order::query()->where('business_id', $businessId)->find($order);

            if ($model === null) {
                throw new NotFoundHttpException;
            }

            if (! OrderStatus::canTransition($model->status, OrderStatus::CANCELLED)) {
                return __('orders.cannot_cancel');
            }

            $model->status = OrderStatus::CANCELLED;
            $model->status_note = $data['status_note'] ?? null;
            $model->save();

            return null;
        });

        return redirect()->route('orders', ['business' => $businessId])
            ->with($error === null ? 'status' : 'error', $error ?? __('orders.cancelled'));
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

    /**
     * Correct the figures on an order the owner has already decided — including
     * one already delivered, where the khata is rewritten by voiding the sale
     * and issuing a corrected one.
     *
     * Unlike accept(), this does not consult OrderStatus: a delivered order is
     * terminal by design, and OrderWriter::reviseOrder is the deliberate bypass.
     * The gate is this method's role check, which is why it lives on the web
     * surface and never on the sync push.
     */
    public function revise(Request $request, string $order): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'), self::ROLES);
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.qty' => ['required', 'integer', 'not_in:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $error = $this->runInTenant($businessId, function () use ($businessId, $order, $data) {
            $model = Order::query()->where('business_id', $businessId)->find($order);

            if ($model === null) {
                throw new NotFoundHttpException;
            }

            try {
                $this->writer->reviseOrder($model->uuid, $data['lines']);
            } catch (ValidationException $e) {
                // Revising a cancelled order — surfaced as a flash message
                // rather than a 422 page, matching how this screen reports
                // every other refusal.
                return $e->validator->errors()->first();
            }

            return null;
        });

        return redirect()->route('orders', ['business' => $businessId])
            ->with($error === null ? 'status' : 'error', $error ?? __('orders.revised'));
    }

    /**
     * The owner's "delete", at any stage including delivered.
     *
     * Nothing is removed: a delivered order's sale is reversed by appending its
     * mirror image, so the khata reads "sale, voided" instead of showing a gap,
     * and the order row survives as cancelled. That is also what lets the change
     * reach the salesmen's phones — sync carries row updates, never deletions.
     */
    public function void(Request $request, string $order): RedirectResponse
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

            $this->writer->voidOrder($model->uuid, $data['status_note'] ?? null);
        });

        return redirect()->route('orders', ['business' => $businessId])
            ->with('status', __('orders.voided'));
    }
}
