<?php
// tests/Feature/Web/PurchasesTest.php
//
// pwOwner/pwSupplier/pwMaterial come from tests/Pest.php.

use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Str;

describe('access', function () {
    it('redirects a guest to login', function () {
        $this->get('/purchases')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())
            ->get('/purchases')->assertRedirect(route('app'));
    });
});

describe('crud', function () {
    it('records a purchase that appears in the list and raises stock', function () {
        [$owner, $business] = pwOwner();
        $s = pwSupplier($business);
        $m = pwMaterial($business);

        $this->actingAs($owner)->post('/purchases', [
            'business' => $business->id, 'supplier_id' => $s->id, 'raw_material_id' => $m->id,
            'purchase_date' => '2026-07-04', 'qty' => '25', 'unit_cost' => '40',
        ])->assertRedirect();

        $this->actingAs($owner)
            ->get('/purchases?business=' . $business->id . '&year=2026&month=7')
            ->assertOk()
            ->assertSee('Besan Traders')
            ->assertSee('₹1,000.00');   // 25 × 40, computed server-side

        // The costed stock-in the purchase implies.
        expect(StockMovement::on('pgsql_migrate')->withoutGlobalScopes()
            ->where('business_id', $business->id)->where('kind', 'in')->count())->toBe(1);
    });

    it('rejects a non-positive quantity or rate', function () {
        [$owner, $business] = pwOwner();
        $s = pwSupplier($business);
        $m = pwMaterial($business);

        $base = ['business' => $business->id, 'supplier_id' => $s->id,
            'raw_material_id' => $m->id, 'purchase_date' => '2026-07-04'];

        $this->actingAs($owner)->post('/purchases', $base + ['qty' => '0', 'unit_cost' => '40'])
            ->assertSessionHasErrors('qty');
        $this->actingAs($owner)->post('/purchases', $base + ['qty' => '10', 'unit_cost' => '0'])
            ->assertSessionHasErrors('unit_cost');

        expect(Purchase::on('pgsql_migrate')->withoutGlobalScopes()->where('business_id', $business->id)->count())->toBe(0);
    });

    it('is idempotent on a replayed uuid', function () {
        [$owner, $business] = pwOwner();
        $s = pwSupplier($business);
        $m = pwMaterial($business);

        $payload = ['business' => $business->id, 'uuid' => (string) Str::uuid(),
            'supplier_id' => $s->id, 'raw_material_id' => $m->id,
            'purchase_date' => '2026-07-04', 'qty' => '25', 'unit_cost' => '40'];

        $this->actingAs($owner)->post('/purchases', $payload);
        $this->actingAs($owner)->post('/purchases', $payload);   // replay

        expect(Purchase::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);
        // and no second stock-in, which would silently double on-hand
        expect(StockMovement::on('pgsql_migrate')->withoutGlobalScopes()->where('business_id', $business->id)->count())->toBe(1);
    });

    it('deleting a purchase archives it and reverses the stock-in', function () {
        [$owner, $business] = pwOwner();
        $s = pwSupplier($business);
        $m = pwMaterial($business);

        $this->actingAs($owner)->post('/purchases', [
            'business' => $business->id, 'supplier_id' => $s->id, 'raw_material_id' => $m->id,
            'purchase_date' => '2026-07-04', 'qty' => '25', 'unit_cost' => '40',
        ]);
        $p = Purchase::on('pgsql_migrate')->where('business_id', $business->id)->firstOrFail();

        $this->actingAs($owner)->delete('/purchases/' . $p->id, ['business' => $business->id])
            ->assertRedirect();

        expect(Purchase::on('pgsql_migrate')->withoutGlobalScopes()->find($p->id)->archived_at)->not->toBeNull();
        expect(StockMovement::on('pgsql_migrate')->withoutGlobalScopes()->where('purchase_id', $p->id)->count())->toBe(0);
    });

    it('refuses a supplier belonging to another tenant', function () {
        [$owner, $business] = pwOwner();
        [, $other] = pwOwner();
        $foreignSupplier = pwSupplier($other, 'Someone Else');
        $m = pwMaterial($business);

        // Invisible under RLS → the writer's findOrFail 404s rather than writing.
        $this->actingAs($owner)->post('/purchases', [
            'business' => $business->id, 'supplier_id' => $foreignSupplier->id,
            'raw_material_id' => $m->id, 'purchase_date' => '2026-07-04',
            'qty' => '25', 'unit_cost' => '40',
        ])->assertNotFound();

        expect(Purchase::on('pgsql_migrate')->withoutGlobalScopes()->count())->toBe(0);
    });

    it('refuses to delete another tenant\'s purchase', function () {
        [$owner, $business] = pwOwner();
        [$otherOwner, $other] = pwOwner();
        $s = pwSupplier($other);
        $m = pwMaterial($other);

        $this->actingAs($otherOwner)->post('/purchases', [
            'business' => $other->id, 'supplier_id' => $s->id, 'raw_material_id' => $m->id,
            'purchase_date' => '2026-07-04', 'qty' => '25', 'unit_cost' => '40',
        ]);
        $foreign = Purchase::on('pgsql_migrate')->where('business_id', $other->id)->firstOrFail();

        $this->actingAs($owner)->delete('/purchases/' . $foreign->id, ['business' => $business->id])
            ->assertRedirect();

        expect(Purchase::on('pgsql_migrate')->withoutGlobalScopes()->find($foreign->id)->archived_at)->toBeNull();
        // its stock-in survives too
        expect(StockMovement::on('pgsql_migrate')->withoutGlobalScopes()->where('purchase_id', $foreign->id)->count())->toBe(1);
    });
});
