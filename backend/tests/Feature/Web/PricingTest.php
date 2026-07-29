<?php
// tests/Feature/Web/PricingTest.php

use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A shop with one product at 93.00/kg and three packs.
 *
 * Mirrors the real catalog this screen was built for: a 300g that clears cost,
 * a 400g that does not, and a 1kg whose cost is derived rather than stored.
 *
 * @return array{0: User, 1: string, 2: Product, 3: array<string, ProductPack>}
 */
function pricingSetup(): array
{
    [$owner, $business] = pwOwner();

    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेंवड़ा', 'name_en' => 'Senvda',
        'base_cost_per_kg' => '93.00',
    ]);

    $packs = [];

    foreach ([['300g', '0.300', '30.00', '27.90'], ['400g', '0.400', '36.00', '37.20'], ['1kg', '1.000', '100.00', null]] as [$label, $weight, $sell, $cost]) {
        $size = PackSize::on('pgsql_migrate')->create([
            'business_id' => $business->id, 'label' => $label, 'weight_kg' => $weight,
        ]);
        $packs[$label] = ProductPack::on('pgsql_migrate')->create([
            'business_id' => $business->id, 'product_id' => $product->id,
            'pack_size_id' => $size->id, 'default_sell_price' => $sell,
            'default_cost_price' => $cost,
        ]);
    }

    return [$owner, $business->id, $product, $packs];
}

describe('access', function () {
    it('redirects a guest to login', function () {
        $this->get('/pricing')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())->get('/pricing')->assertRedirect(route('app'));
    });

    it('refuses a salesman, since setting cost is not a daily operational job', function () {
        [, $businessId] = pricingSetup();
        $salesman = User::factory()->create();
        App\Models\Membership::on('pgsql_migrate')->create([
            'user_id' => $salesman->id, 'business_id' => $businessId, 'role' => 'salesman',
        ]);

        $this->actingAs($salesman)->get('/pricing?business=' . $businessId)->assertRedirect(route('app'));
    });
});

describe('the screen', function () {
    it('shows each pack with its cost and selling price', function () {
        [$owner, $businessId] = pricingSetup();

        $this->actingAs($owner)->get('/pricing?business=' . $businessId)
            ->assertOk()
            ->assertSee('Senvda')
            ->assertSee('300g')
            ->assertSee('93.00', false);
    });

    it('shows the margin, and a loss where the pack sells under cost', function () {
        // 400g sells at 36.00 against a 37.20 cost — one of the eleven packs
        // that made this screen necessary.
        [$owner, $businessId] = pricingSetup();

        $this->actingAs($owner)->get('/pricing?business=' . $businessId)
            ->assertOk()
            // U+2212 MINUS, not an ASCII hyphen — Inr::format matches money.js
            // so the printed report and the phone agree.
            ->assertSee('−₹1.20')      // the loss, in money
            ->assertSee('₹2.10');      // the 300g's margin, for contrast
    });

    it('says when a pack cost is derived from the per-kg figure rather than stored', function () {
        // The 1kg has no stored cost, so the floor derives 93.00 from per-kg.
        // Without this label an owner cannot tell why editing per-kg moved one
        // row and not the others.
        [$owner, $businessId] = pricingSetup();

        $this->actingAs($owner)->get('/pricing?business=' . $businessId)
            ->assertOk()
            ->assertSee(__('pricing.from_per_kg', ['value' => '₹93.00']))
            ->assertSee(__('pricing.overrides_per_kg'));
    });

    it('is reachable from the dashboard, not by URL alone', function () {
        [$owner, $businessId] = pricingSetup();

        $this->actingAs($owner)->get('/reports/dashboard?business=' . $businessId)
            ->assertOk()
            ->assertSee(route('pricing', ['business' => $businessId]), false);
    });
});

describe('saving', function () {
    it('stores a new per-kg cost and per-pack prices', function () {
        [$owner, $businessId, $product, $packs] = pricingSetup();

        $this->actingAs($owner)->post('/pricing', [
            'business' => $businessId,
            'products' => [$product->id => ['base_cost_per_kg' => '95.50']],
            'packs' => [
                $packs['300g']->id => ['default_cost_price' => '28.65', 'default_sell_price' => '32.00'],
            ],
        ])->assertRedirect(route('pricing', ['business' => $businessId]));

        expect((string) DB::connection('pgsql_migrate')->table('products')
            ->where('id', $product->id)->value('base_cost_per_kg'))->toBe('95.50');
        expect((string) DB::connection('pgsql_migrate')->table('product_packs')
            ->where('id', $packs['300g']->id)->value('default_sell_price'))->toBe('32.00');
    });

    it('treats an emptied cost box as "no cost", not as zero', function () {
        // Zero is a real, different answer — a free issue — and PriceFloor
        // honours it. Storing zero for a blank would drop the floor to nothing.
        [$owner, $businessId, $product, $packs] = pricingSetup();

        $this->actingAs($owner)->post('/pricing', [
            'business' => $businessId,
            'packs' => [
                $packs['300g']->id => ['default_cost_price' => '', 'default_sell_price' => '30.00'],
            ],
        ])->assertRedirect();

        expect(DB::connection('pgsql_migrate')->table('product_packs')
            ->where('id', $packs['300g']->id)->value('default_cost_price'))->toBeNull();
    });

    it('rejects a negative price rather than storing it', function () {
        [$owner, $businessId, , $packs] = pricingSetup();

        $this->actingAs($owner)->post('/pricing', [
            'business' => $businessId,
            'packs' => [
                $packs['300g']->id => ['default_cost_price' => '10.00', 'default_sell_price' => '-5.00'],
            ],
        ])->assertSessionHasErrors();

        expect((string) DB::connection('pgsql_migrate')->table('product_packs')
            ->where('id', $packs['300g']->id)->value('default_sell_price'))->toBe('30.00');
    });

    it('does not touch another tenant\'s pack', function () {
        [$owner, $businessId] = pricingSetup();
        [, , , $theirPacks] = pricingSetup();

        $this->actingAs($owner)->post('/pricing', [
            'business' => $businessId,
            'packs' => [
                $theirPacks['300g']->id => ['default_cost_price' => '1.00', 'default_sell_price' => '1.00'],
            ],
        ])->assertRedirect();

        // Skipped silently under RLS rather than 404ing, so one stale id in a
        // form cannot lose the rest of a good submission.
        expect((string) DB::connection('pgsql_migrate')->table('product_packs')
            ->where('id', $theirPacks['300g']->id)->value('default_sell_price'))->toBe('30.00');
    });
});

describe('recosting', function () {
    it('sets every pack cost from the per-kg figure', function () {
        // The trap this action exists for: default_cost_price wins over
        // base_cost_per_kg, so typing a new per-kg cost alone changes nothing.
        [$owner, $businessId, $product, $packs] = pricingSetup();

        $this->actingAs($owner)->post('/pricing/' . $product->id . '/recost', [
            'business' => $businessId,
        ])->assertRedirect();

        $costs = DB::connection('pgsql_migrate')->table('product_packs')
            ->whereIn('id', collect($packs)->pluck('id'))->pluck('default_cost_price', 'id');

        expect((string) $costs[$packs['300g']->id])->toBe('27.90');  // 93 × 0.300
        expect((string) $costs[$packs['400g']->id])->toBe('37.20');  // 93 × 0.400
        expect((string) $costs[$packs['1kg']->id])->toBe('93.00');   // was derived, now stored
    });

    it('rounds a recosted pack UP to the paisa, never below true cost', function () {
        // PriceFloor rounds a DERIVED floor up. Storing a truncated value here
        // would quietly set the floor a paisa under cost the moment it is
        // stored rather than derived. CatalogService truncates, so it is
        // deliberately not reused for this.
        [$owner, $businessId, $product, $packs] = pricingSetup();

        DB::connection('pgsql_migrate')->table('products')
            ->where('id', $product->id)->update(['base_cost_per_kg' => '93.33']);

        $this->actingAs($owner)->post('/pricing/' . $product->id . '/recost', [
            'business' => $businessId,
        ])->assertRedirect();

        // 93.33 × 0.300 = 27.999 exactly → must land on 28.00, not 27.99.
        expect((string) DB::connection('pgsql_migrate')->table('product_packs')
            ->where('id', $packs['300g']->id)->value('default_cost_price'))->toBe('28.00');
    });

    it('refuses to recost a product with no per-kg cost, rather than zeroing it', function () {
        [$owner, $businessId, $product, $packs] = pricingSetup();
        DB::connection('pgsql_migrate')->table('products')
            ->where('id', $product->id)->update(['base_cost_per_kg' => null]);

        $this->actingAs($owner)->post('/pricing/' . $product->id . '/recost', [
            'business' => $businessId,
        ])->assertRedirect();

        expect((string) DB::connection('pgsql_migrate')->table('product_packs')
            ->where('id', $packs['300g']->id)->value('default_cost_price'))->toBe('27.90');
    });
});
