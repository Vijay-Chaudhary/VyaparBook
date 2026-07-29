<?php
// app/Services/OrderWriter.php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductPack;
use App\Orders\OrderStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    public function __construct(private readonly LedgerWriter $ledger) {}

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

        // findOrFail under RLS: another tenant's customer is invisible → 404.
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
            $order->status = OrderStatus::PENDING;
            $order->total = $total;
            $order->created_by = app('tenant.user_id');
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
}
