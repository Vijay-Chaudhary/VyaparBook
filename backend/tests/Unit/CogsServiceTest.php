<?php
// tests/Unit/CogsServiceTest.php
//
// pwInTenant/pwMaterial and cogsProduct/cogsBatch/cogsBuy come from tests/Pest.php.

use App\Models\Business;
use App\Models\User;
use App\Services\CogsService;
use Illuminate\Support\Facades\DB;

it('costs a product from what its batches actually consumed', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    $besan = pwMaterial($a, 'Besan');
    $oil = pwMaterial($a, 'Oil');
    cogsBuy($a, $u, $besan, '100', '40');   // ₹40/kg
    cogsBuy($a, $u, $oil, '20', '150');     // ₹150/kg

    [$sev, $sevPack] = cogsProduct($a, 'Sev', '1.000', '93.00');

    // Two batches: 60kg from 50kg besan + 5kg oil (2000 + 750 = ₹2,750),
    // then 40kg from 30kg besan + 4kg oil (1200 + 600 = ₹1,800).
    // Total ₹4,550 for 100kg → ₹45.50/kg.
    cogsBatch($a, $u, $sev, '60.000', [$besan->id => '50.000', $oil->id => '5.000']);
    cogsBatch($a, $u, $sev, '40.000', [$besan->id => '30.000', $oil->id => '4.000']);

    [$materials, $products, $packs] = pwInTenant($a->id, function () use ($a) {
        $svc = new CogsService();

        return [$svc->materialCostPerKg($a->id), $svc->productCostPerKg($a->id), $svc->packCosts($a->id)];
    });

    expect($materials[$besan->id])->toBe('40.00');
    expect($materials[$oil->id])->toBe('150.00');
    expect($products[$sev->id])->toBe('45.50');

    // A 1kg pack costs one kg of product — and it is an ACTUAL cost, not the
    // ₹93.00 the owner had guessed.
    expect($packs[$sevPack->id]->costRupees)->toBe('45.50');
    expect($packs[$sevPack->id]->fromProduction)->toBeTrue();
});

it('scales the pack cost by the pack weight', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    $besan = pwMaterial($a, 'Besan');
    cogsBuy($a, $u, $besan, '100', '40');

    // 10kg out of 10kg besan → ₹40/kg; a 5kg pack therefore costs ₹200.
    [$sev, $pack] = cogsProduct($a, 'Sev', '5.000', '93.00');
    cogsBatch($a, $u, $sev, '10.000', [$besan->id => '10.000']);

    $packs = pwInTenant($a->id, fn () => (new CogsService())->packCosts($a->id));

    expect($packs[$pack->id]->costRupees)->toBe('200.00');
});

it('falls back to the owner estimate for a product with no production', function () {
    $a = Business::factory()->create();

    // Bought-in goods: never produced, so there is no actual cost to compute.
    [, $pack] = cogsProduct($a, 'Namkeen', '1.000', '77.00');

    [$products, $packs] = pwInTenant($a->id, function () use ($a) {
        $svc = new CogsService();

        return [$svc->productCostPerKg($a->id), $svc->packCosts($a->id)];
    });

    expect($products)->toBe([]);
    expect($packs[$pack->id]->costRupees)->toBe('77.00');
    expect($packs[$pack->id]->fromProduction)->toBeFalse();
});

it('does not divide by zero when a batch produced nothing', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    $besan = pwMaterial($a, 'Besan');
    cogsBuy($a, $u, $besan, '100', '40');

    // A spoiled batch: material consumed, nothing came out.
    [$sev, $pack] = cogsProduct($a, 'Sev', '1.000', '93.00');
    cogsBatch($a, $u, $sev, '0.000', [$besan->id => '10.000']);

    [$products, $packs] = pwInTenant($a->id, function () use ($a) {
        $svc = new CogsService();

        return [$svc->productCostPerKg($a->id), $svc->packCosts($a->id)];
    });

    // Not costable — so the estimate stands rather than a divide-by-zero or ₹0.
    expect($products)->toBe([]);
    expect($packs[$pack->id]->costRupees)->toBe('93.00');
    expect($packs[$pack->id]->fromProduction)->toBeFalse();
});

it('treats a null estimate as zero, as before Phase 2b', function () {
    $a = Business::factory()->create();
    [, $pack] = cogsProduct($a, 'Unpriced', '1.000', null);

    $packs = pwInTenant($a->id, fn () => (new CogsService())->packCosts($a->id));

    expect($packs[$pack->id]->costRupees)->toBe('0.00');
    expect($packs[$pack->id]->fromProduction)->toBeFalse();
});

it('memoizes the costing chain so its queries run once per request', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    $besan = pwMaterial($a, 'Besan');
    cogsBuy($a, $u, $besan, '100', '40');
    [$sev, $pack] = cogsProduct($a, 'Sev', '1.000', '93.00');
    cogsBatch($a, $u, $sev, '10.000', [$besan->id => '10.000']);

    [$cold, $warm] = pwInTenant($a->id, function () use ($a) {
        $svc = new CogsService();
        $db = DB::connection();

        $db->enableQueryLog();
        $svc->packCosts($a->id);            // cold: runs the whole chain
        $cold = count($db->getQueryLog());

        $db->flushQueryLog();
        $svc->packCosts($a->id);            // warm: served from the memo,
        $svc->productCostPerKg($a->id);     // ...as are the upstream links
        $svc->materialCostPerKg($a->id);
        $warm = count($db->getQueryLog());

        return [$cold, $warm];
    });

    expect($cold)->toBeGreaterThan(0);      // it really queried the first time
    expect($warm)->toBe(0);                 // and not once more
});

it('never costs one tenant\'s product from another tenant\'s production', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();
    $u = User::factory()->create();

    $besanA = pwMaterial($a, 'Besan');
    cogsBuy($a, $u, $besanA, '100', '40');
    [$sevA, $packA] = cogsProduct($a, 'Sev', '1.000', '93.00');
    cogsBatch($a, $u, $sevA, '10.000', [$besanA->id => '10.000']);   // ₹40/kg

    $besanB = pwMaterial($b, 'Besan');
    cogsBuy($b, $u, $besanB, '100', '900');                           // wildly different
    [$sevB, $packB] = cogsProduct($b, 'Sev', '1.000', '93.00');
    cogsBatch($b, $u, $sevB, '10.000', [$besanB->id => '10.000']);   // ₹900/kg

    $fromA = pwInTenant($a->id, fn () => (new CogsService())->packCosts($a->id));

    expect($fromA[$packA->id]->costRupees)->toBe('40.00');
    expect($fromA)->not->toHaveKey($packB->id);
});
