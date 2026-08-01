<?php
// tests/Feature/Web/SuppliersTest.php
//
// pwOwner/pwSupplier/pwMaterial come from tests/Pest.php.

use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Support\Str;

describe('access', function () {
    it('redirects a guest to login', function () {
        $this->get('/suppliers')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())
            ->get('/suppliers')->assertRedirect(route('app'));
    });
});

describe('crud', function () {
    it('adds a supplier and lists it with its opening balance outstanding', function () {
        [$owner, $business] = pwOwner();

        $this->actingAs($owner)->post('/suppliers', [
            'business' => $business->id, 'name' => 'Besan Traders',
            'village' => 'Rampur', 'opening_balance' => '500',
        ])->assertRedirect();

        $this->actingAs($owner)->get('/suppliers?business=' . $business->id)
            ->assertOk()
            ->assertSee('Besan Traders')
            ->assertSee('Rampur')
            ->assertSee('₹500.00');   // no activity yet → outstanding = opening
    });

    it('requires a name and is idempotent on a replayed uuid', function () {
        [$owner, $business] = pwOwner();

        $this->actingAs($owner)->post('/suppliers', ['business' => $business->id, 'name' => ''])
            ->assertSessionHasErrors('name');

        $payload = ['business' => $business->id, 'uuid' => (string) Str::uuid(), 'name' => 'Dal Mill'];
        $this->actingAs($owner)->post('/suppliers', $payload);
        $this->actingAs($owner)->post('/suppliers', $payload);   // replay

        expect(Supplier::where('business_id', $business->id)->count())->toBe(1);
    });

    it('shows a ledger whose running balance reconciles with outstanding', function () {
        [$owner, $business] = pwOwner();
        $s = pwSupplier($business, 'Besan Traders', '500.00');
        $m = pwMaterial($business);

        // 25kg @ ₹40 = ₹1,000 → owed 1,500; pay ₹600 → owed 900.
        $this->actingAs($owner)->post('/purchases', [
            'business' => $business->id, 'supplier_id' => $s->id, 'raw_material_id' => $m->id,
            'purchase_date' => '2026-07-04', 'qty' => '25', 'unit_cost' => '40',
        ]);
        $this->actingAs($owner)->post('/suppliers/' . $s->id . '/payments', [
            'business' => $business->id, 'amount' => '600',
            'payment_date' => '2026-07-06', 'mode' => 'cash',
        ])->assertRedirect();

        $this->actingAs($owner)->get('/suppliers/' . $s->id . '?business=' . $business->id)
            ->assertOk()
            ->assertSee('₹1,000.00')    // the purchase line
            ->assertSee('−₹600.00')     // the payment, credited
            ->assertSee('₹900.00');     // running balance = outstanding

        expect(SupplierPayment::where('business_id', $business->id)->count())->toBe(1);
    });

    it('rejects a non-positive payment and an unknown mode', function () {
        [$owner, $business] = pwOwner();
        $s = pwSupplier($business);

        $this->actingAs($owner)->post('/suppliers/' . $s->id . '/payments', [
            'business' => $business->id, 'amount' => '0', 'payment_date' => '2026-07-06', 'mode' => 'cash',
        ])->assertSessionHasErrors('amount');

        $this->actingAs($owner)->post('/suppliers/' . $s->id . '/payments', [
            'business' => $business->id, 'amount' => '10', 'payment_date' => '2026-07-06', 'mode' => 'barter',
        ])->assertSessionHasErrors('mode');

        expect(SupplierPayment::withoutGlobalScopes()->count())->toBe(0);
    });

    it('does not leak or accept another tenant\'s supplier', function () {
        [$owner, $business] = pwOwner();
        [, $other] = pwOwner();
        $foreign = pwSupplier($other, 'Other Shop Supplier', '9999.00');

        // Absent from the list…
        $this->actingAs($owner)->get('/suppliers?business=' . $business->id)
            ->assertOk()
            ->assertDontSee('Other Shop Supplier');

        // …its detail page is not reachable…
        $this->actingAs($owner)->get('/suppliers/' . $foreign->id . '?business=' . $business->id)
            ->assertRedirect(route('suppliers', ['business' => $business->id]));

        // …and a payment cannot be pinned to it.
        $this->actingAs($owner)->post('/suppliers/' . $foreign->id . '/payments', [
            'business' => $business->id, 'amount' => '100', 'payment_date' => '2026-07-06', 'mode' => 'cash',
        ])->assertRedirect();

        expect(SupplierPayment::withoutGlobalScopes()->count())->toBe(0);
    });
});
