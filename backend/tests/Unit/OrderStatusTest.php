<?php
// tests/Unit/OrderStatusTest.php

use App\Orders\OrderStatus;

it('knows which states are terminal', function () {
    expect(OrderStatus::isTerminal(OrderStatus::DELIVERED))->toBeTrue();
    expect(OrderStatus::isTerminal(OrderStatus::REJECTED))->toBeTrue();
    expect(OrderStatus::isTerminal(OrderStatus::CANCELLED))->toBeTrue();

    expect(OrderStatus::isTerminal(OrderStatus::PENDING))->toBeFalse();
    expect(OrderStatus::isTerminal(OrderStatus::ACCEPTED))->toBeFalse();
    expect(OrderStatus::isTerminal(OrderStatus::PACKED))->toBeFalse();
});

it('allows the linear path one step at a time', function () {
    expect(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::ACCEPTED))->toBeTrue();
    expect(OrderStatus::canTransition(OrderStatus::ACCEPTED, OrderStatus::PACKED))->toBeTrue();
    expect(OrderStatus::canTransition(OrderStatus::PACKED, OrderStatus::DELIVERED))->toBeTrue();
});

it('refuses to skip a step, so a delivered order was always packed', function () {
    expect(OrderStatus::canTransition(OrderStatus::ACCEPTED, OrderStatus::DELIVERED))->toBeFalse();
    expect(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::PACKED))->toBeFalse();
});

it('refuses to move backwards, so a replayed push cannot rewind an order', function () {
    expect(OrderStatus::canTransition(OrderStatus::PACKED, OrderStatus::ACCEPTED))->toBeFalse();
    expect(OrderStatus::canTransition(OrderStatus::DELIVERED, OrderStatus::PACKED))->toBeFalse();
});

it('refuses to stay put — a repeat is handled by the caller, not a transition', function () {
    expect(OrderStatus::canTransition(OrderStatus::PACKED, OrderStatus::PACKED))->toBeFalse();
});

it('allows rejection only from pending', function () {
    expect(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::REJECTED))->toBeTrue();
    expect(OrderStatus::canTransition(OrderStatus::ACCEPTED, OrderStatus::REJECTED))->toBeFalse();
    expect(OrderStatus::canTransition(OrderStatus::PACKED, OrderStatus::REJECTED))->toBeFalse();
});

it('allows cancellation from any non-terminal state', function () {
    expect(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::CANCELLED))->toBeTrue();
    expect(OrderStatus::canTransition(OrderStatus::ACCEPTED, OrderStatus::CANCELLED))->toBeTrue();
    expect(OrderStatus::canTransition(OrderStatus::PACKED, OrderStatus::CANCELLED))->toBeTrue();
});

it('never leaves a terminal state, whatever the target', function () {
    foreach ([OrderStatus::DELIVERED, OrderStatus::REJECTED, OrderStatus::CANCELLED] as $terminal) {
        foreach (OrderStatus::all() as $target) {
            expect(OrderStatus::canTransition($terminal, $target))->toBeFalse();
        }
    }
});

it('rejects an unknown status rather than guessing', function () {
    expect(OrderStatus::canTransition('banana', OrderStatus::ACCEPTED))->toBeFalse();
    expect(OrderStatus::canTransition(OrderStatus::PENDING, 'banana'))->toBeFalse();
});
