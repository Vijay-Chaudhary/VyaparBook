<?php
// app/Services/OrderWriter.php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductPack;
use App\Orders\OrderStatus;
use App\Pricing\PriceFloor;
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

                // The same floor a sale is held to. An order below cost would
                // only be refused later at delivery, after the shop was told.
                $floor = PriceFloor::for($pack);
                if ($floor !== null && bccomp($rate, $floor, 2) < 0) {
                    throw ValidationException::withMessages([
                        'lines' => __('sales.rate_below_floor', [
                            'product' => $pack->product?->name_en ?: $pack->product?->name_hi ?: 'this product',
                            'floor' => $floor,
                        ]),
                    ]);
                }

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

            // A repeat of the SAME status is a duplicate, not an error — the
            // phone resent its outbox. But that grace does not extend to an
            // order already in a terminal state: cancelling an already-cancelled
            // order is not "the same cancel resent", it's acting on a closed
            // order, and OrderStatus::canTransition would refuse it below
            // anyway — checking isTerminal here just makes that explicit rather
            // than silently reporting a false "duplicate".
            if ($order->status === $to && ! OrderStatus::isTerminal($order->status)) {
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
}
