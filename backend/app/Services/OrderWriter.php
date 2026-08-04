<?php
// app/Services/OrderWriter.php

namespace App\Services;

use App\Ledger\LedgerReverser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Orders\OrderStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * The one home for order writes, mirroring LedgerWriter's shape: every method
 * is idempotent by (business_id, uuid), stamps business_id and created_by from
 * the tenant pin rather than the payload, and returns [model, bool $created] so
 * the caller can map applied vs duplicate.
 *
 * An order is NOT money. Nothing here touches the khata — that happens exactly
 * once, in deliver(), which routes through LedgerWriter::createSale.
 */
class OrderWriter
{
    /**
     * Roles whose own orders skip the approval queue, and the only roles that
     * may revise or void one. Mirrors OrderController::ROLES deliberately: the
     * approvals screen already admits exactly these two.
     */
    public const SELF_APPROVING_ROLES = ['owner', 'admin'];

    public function __construct(
        private readonly LedgerWriter $ledger,
        private readonly LedgerReverser $reverser,
    ) {}

    /** @return array<string, array<int, mixed>> */
    public static function rulesForOrder(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'customer_id' => ['required', 'uuid'],
            'order_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_pack_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'integer', 'not_in:0'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }

    /** @return array{0: Order, 1: bool} */
    public function createOrder(array $data): array
    {
        $existing = Order::where('uuid', $data['uuid'])->first();
        if ($existing) {
            return [$existing->load('lines'), false];
        }

        // findOrFail under the tenant scope: another tenant's customer is invisible → 404.
        $customer = Customer::findOrFail($data['customer_id']);

        $packIds = array_column($data['lines'], 'product_pack_id');
        $packs = ProductPack::with(['product', 'packSize'])->whereIn('id', $packIds)->get()->keyBy('id');

        $order = DB::transaction(function () use ($data, $customer, $packs) {
            $lines = [];
            $total = '0.00';

            foreach ($data['lines'] as $line) {
                $pack = $packs[$line['product_pack_id']] ?? null;

                if ($pack === null) {
                    throw (new ModelNotFoundException)->setModel(ProductPack::class, [$line['product_pack_id']]);
                }

                $rate = isset($line['rate'])
                    ? bcadd((string) $line['rate'], '0', 2)
                    : bcadd((string) $pack->default_sell_price, '0', 2);

                // No floor check. Below cost is a decision the shop is allowed
                // to make — it sells some packs at or under cost deliberately —
                // so the phone warns and confirms rather than the server
                // refusing. Delivery re-runs the same (absent) rule, so an
                // order accepted below cost still becomes a sale.
                $lineTotal = bcmul($rate, (string) $line['qty'], 2);
                $lines[] = [
                    'product_pack_id' => $pack->id,
                    'qty' => $line['qty'],
                    'rate' => $rate,
                    'line_total' => $lineTotal,
                ];
                $total = bcadd($total, $lineTotal, 2);
            }

            $order = new Order([
                'business_id' => app('tenant.id'),
                'uuid' => $data['uuid'],
                'customer_id' => $customer->id,
                'order_date' => $data['order_date'],
            ]);
            // An approver placing their own order has nobody to wait for: the
            // approval queue exists so the owner decides what a SALESMAN asked
            // for, and routing an owner's order into it only asks them to click
            // accept on their own work. Same two roles the approvals screen
            // admits (OrderController::ROLES), so this grants nothing new — it
            // just skips a step they could already take themselves.
            $selfApproves = in_array(app('tenant.role'), self::SELF_APPROVING_ROLES, true);

            $order->status = $selfApproves ? OrderStatus::ACCEPTED : OrderStatus::PENDING;
            $order->total = $total;
            $order->created_by = app('tenant.user_id');

            if ($selfApproves) {
                // Stamped as though they had accepted it, because they did —
                // the approvals screen writes exactly these two fields, and
                // leaving them null would make a self-approved order look like
                // it reached 'accepted' with nobody responsible for it.
                $order->accepted_by = app('tenant.user_id');
                $order->accepted_at = now();
            }

            $order->save();

            foreach ($lines as $l) {
                $orderLine = new OrderLine([
                    'business_id' => app('tenant.id'),
                    'order_id' => $order->id,
                    'product_pack_id' => $l['product_pack_id'],
                    'qty' => $l['qty'],
                    'rate' => $l['rate'],
                ]);
                $orderLine->line_total = $l['line_total'];
                // What was asked for, captured before anyone can edit it.
                // Stamped from the values just validated above rather than
                // taken from the payload, so a phone cannot claim it ordered
                // something it did not. Acceptance overwrites qty/rate; these
                // two are written once and never again.
                $orderLine->ordered_qty = $l['qty'];
                $orderLine->ordered_rate = $l['rate'];
                $orderLine->save();
            }

            return $order;
        });

        return [$order->load('lines'), true];
    }

    /**
     * Move an order to $to, or report that it is already there.
     *
     * A repeat of the same state is a duplicate, not an error — the phone
     * resent its outbox. An illegal move (skipping a step, going backwards,
     * touching a terminal order) throws, so the sync push parks that one
     * mutation and the batch continues.
     *
     * @return array{0: Order, 1: bool}
     */
    private function transition(string $orderUuid, string $to, ?string $note = null): array
    {
        return DB::transaction(function () use ($orderUuid, $to, $note) {
            $order = Order::where('uuid', $orderUuid)->first();

            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [$orderUuid]);
            }

            // Same status is an idempotent repeat, not an error — a phone
            // retrying its outbox must not have a succeeded cancel parked.
            // Moving to a DIFFERENT terminal state is caught by canTransition
            // below.
            if ($order->status === $to) {
                return [$order, false];
            }

            if (! OrderStatus::canTransition($order->status, $to)) {
                throw ValidationException::withMessages([
                    'status' => __('orders.illegal_transition', ['from' => $order->status, 'to' => $to]),
                ]);
            }

            $order->status = $to;
            if ($note !== null) {
                $order->status_note = $note;
            }
            $order->save();

            return [$order, true];
        });
    }

    /** @return array{0: Order, 1: bool} */
    public function pack(string $orderUuid): array
    {
        return $this->transition($orderUuid, OrderStatus::PACKED);
    }

    /** @return array{0: Order, 1: bool} */
    public function cancel(string $orderUuid, ?string $note = null): array
    {
        return $this->transition($orderUuid, OrderStatus::CANCELLED, $note);
    }

    /**
     * Delivery is the money event: it creates the sale.
     *
     * The sale reuses the ORDER's uuid. createSale is already idempotent by
     * (business_id, uuid), so a replayed delivery returns the existing sale
     * instead of doubling a customer's khata — the guarantee comes free from
     * machinery that is already correct.
     *
     * sale_date is today, not the order date: the sale records goods arriving.
     * created_by is stamped by LedgerWriter from the tenant pin, so it is
     * whoever delivered, not whoever took the order.
     *
     * @return array{0: Order, 1: bool}
     */
    public function deliver(string $orderUuid): array
    {
        return DB::transaction(function () use ($orderUuid) {
            $order = Order::with('lines')->where('uuid', $orderUuid)->first();

            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [$orderUuid]);
            }

            if ($order->status === OrderStatus::DELIVERED) {
                return [$order, false];
            }

            if (! OrderStatus::canTransition($order->status, OrderStatus::DELIVERED)) {
                throw ValidationException::withMessages([
                    'status' => __('orders.illegal_transition', [
                        'from' => $order->status, 'to' => OrderStatus::DELIVERED,
                    ]),
                ]);
            }

            [$sale] = $this->ledger->createSale([
                'uuid' => $order->uuid,
                'customer_id' => $order->customer_id,
                'sale_date' => now()->toDateString(),
                'lines' => $order->lines->map(fn (OrderLine $l) => [
                    'product_pack_id' => $l->product_pack_id,
                    'qty' => $l->qty,
                    'rate' => (string) $l->rate,
                ])->all(),
            ]);

            $order->status = OrderStatus::DELIVERED;
            $order->sale_id = $sale->id;
            $order->save();

            return [$order, true];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Owner corrections
    |--------------------------------------------------------------------------
    |
    | The two methods below are the ONLY things in the app that act on a
    | terminal order, and they deliberately do not route through transition().
    |
    | OrderStatus refuses to leave a terminal state, and that refusal is load
    | bearing: it is what stops a phone that was offline for two days pushing a
    | stale `pack` and resurrecting an order the owner already cancelled. Adding
    | delivered → cancelled to the state machine would hand that power to every
    | replayed mutation in the outbox. So these bypass the machine explicitly
    | instead, and stay off the sync surface — the controller reaches them only
    | after ResolvesOwnedTenant has confirmed an owner/admin session.
    |
    | Nothing is destroyed either way. A correction voids the sale by appending
    | its mirror image (LedgerReverser) and, for a revision, writes a fresh one;
    | the khata reads "sale, voided, corrected" rather than showing a gap where
    | money used to be.
    |
    */

    /**
     * Void an order outright — the owner's "delete", at any stage.
     *
     * A delivered order's sale is reversed first, so the customer stops owing
     * for goods that are coming back. Everything earlier has no sale to undo.
     *
     * @return array{0: Order, 1: bool} false when it was already cancelled
     */
    public function voidOrder(string $orderUuid, ?string $note = null): array
    {
        return DB::transaction(function () use ($orderUuid, $note) {
            $order = $this->lockOrder($orderUuid);

            // A repeat is a duplicate, not an error — same convention as
            // transition(). Re-voiding must not append a second reversal.
            if ($order->status === OrderStatus::CANCELLED) {
                return [$order, false];
            }

            $this->voidSaleFor($order);

            $order->status = OrderStatus::CANCELLED;
            if ($note !== null) {
                $order->status_note = $note;
            }
            $order->save();

            return [$order, true];
        });
    }

    /**
     * Correct the quantities or rates on an order, including a delivered one.
     *
     * On a delivered order the khata is rewritten the only safe way there is:
     * void the sale that exists, then issue a corrected one. The order keeps
     * its delivered status throughout — the goods did go out, only the figures
     * were wrong.
     *
     * $lines is keyed by order_line id; a line the caller omits keeps what it
     * has, mirroring how the approvals screen merges its edits.
     *
     * @param  array<string, array{qty: int|string, rate: int|string}>  $lines
     * @return array{0: Order, 1: bool}
     */
    public function reviseOrder(string $orderUuid, array $lines): array
    {
        return DB::transaction(function () use ($orderUuid, $lines) {
            $order = $this->lockOrder($orderUuid);

            // A cancelled or rejected order has no figures worth correcting,
            // and revising one would quietly resurrect it.
            if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::REJECTED], true)) {
                throw ValidationException::withMessages([
                    'status' => __('orders.cannot_revise', ['status' => $order->status]),
                ]);
            }

            $total = '0.00';

            foreach ($order->lines as $line) {
                $edit = $lines[$line->id] ?? null;
                $qty = $edit !== null ? (int) $edit['qty'] : $line->qty;
                $rate = $edit !== null ? bcadd((string) $edit['rate'], '0', 2) : (string) $line->rate;

                $lineTotal = bcmul($rate, (string) $qty, 2);
                $total = bcadd($total, $lineTotal, 2);

                $line->qty = $qty;
                $line->rate = $rate;
                $line->line_total = $lineTotal;
                // ordered_qty / ordered_rate are NOT touched. They are what the
                // salesman asked for, and OrderAdjustment reads them to show the
                // owner what acceptance changed. An owner correction is one more
                // thing that happened to the order, not a rewrite of its history.
                $line->save();
            }

            $order->total = $total;

            if ($order->status === OrderStatus::DELIVERED) {
                $this->voidSaleFor($order);

                // Bumped BEFORE the sale is written, so the corrected sale is
                // keyed on the new revision. See the migration's docblock: a
                // sale re-using the order uuid would be handed the original
                // back by createSale's idempotency check and change nothing.
                $order->revision++;

                [$sale] = $this->ledger->createSale([
                    'uuid' => $this->saleUuidFor($order),
                    'customer_id' => $order->customer_id,
                    'sale_date' => now()->toDateString(),
                    'lines' => $order->lines->map(fn (OrderLine $l) => [
                        'product_pack_id' => $l->product_pack_id,
                        'qty' => $l->qty,
                        'rate' => (string) $l->rate,
                    ])->all(),
                ]);

                $order->sale_id = $sale->id;
            }

            $order->save();

            return [$order->load('lines'), true];
        });
    }

    /** Load an order for correction, or 404 exactly as transition() does. */
    private function lockOrder(string $orderUuid): Order
    {
        $order = Order::with('lines')->where('uuid', $orderUuid)->first();

        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderUuid]);
        }

        return $order;
    }

    /**
     * Reverse whatever sale the order currently points at, and unlink it.
     *
     * Always the CURRENT sale_id, never the original: after one correction that
     * is the corrected sale, which carries no reverses_id of its own and so
     * passes LedgerReverser's "cannot void a reversal" guard. That is what lets
     * an owner correct the same order repeatedly.
     */
    private function voidSaleFor(Order $order): void
    {
        if ($order->sale_id === null) {
            return;
        }

        $sale = Sale::find($order->sale_id);

        if ($sale !== null) {
            $this->reverser->voidSale($sale);
        }

        $order->sale_id = null;
    }

    /**
     * The idempotency key for an order's sale at its current revision.
     *
     * Revision 0 is the bare order uuid, which is what deliver() has always
     * used — so this changes no existing row. Later revisions derive a stable
     * uuid5 from it, making a double-submitted correction replay onto the same
     * corrected sale instead of writing a second one.
     */
    private function saleUuidFor(Order $order): string
    {
        if ($order->revision === 0) {
            return $order->uuid;
        }

        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "vyaparbook:order:{$order->uuid}:rev:{$order->revision}",
        );
    }
}
