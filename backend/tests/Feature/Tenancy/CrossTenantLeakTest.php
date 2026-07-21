<?php
// tests/Feature/Tenancy/CrossTenantLeakTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

function ownerContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return [$owner, $business, (new TokenService())->issue($owner, $membership)];
}

it('never returns business Bs memberships in business As mine listing', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/businesses/mine')
        ->assertOk();

    $businessIds = collect($response->json())->pluck('business.id');

    expect($businessIds)->toContain($businessA->id);
    expect($businessIds)->not->toContain($businessB->id);
});

it('rejects business As owner switching into business B', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/businesses/{$businessB->id}/switch")
        ->assertStatus(403);
});

it('rejects a token forged with another tenants tid without a matching membership', function () {
    [$ownerA, $businessA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $forgedToken = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::claims([
        'tid' => $businessB->id,
        'role' => 'owner',
    ])->fromUser($ownerA);

    $this->withHeader('Authorization', "Bearer {$forgedToken}")
        ->getJson('/api/v1/whoami')
        ->assertStatus(403);
});

it('rejects business As owner inviting staff into business B via a mismatched path id', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    // Owner A's token is scoped to business A; RequireTenant only confirms *a* tenant
    // is active, so the controller itself must not trust the {id} path segment blindly.
    // Since invite's business_id always comes from app('tenant.id'), not the path param,
    // this proves the invite is created for A, never for the B id in the URL.
    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/businesses/{$businessB->id}/invite", ['role' => 'salesman'])
        ->assertCreated();

    // latest('created_at'), not latest('id'): Invite uses HasUuids, so ordering
    // by id sorts UUIDs lexically rather than chronologically.
    $invite = \App\Models\Invite::latest('created_at')->first();
    expect($invite->business_id)->toBe($businessA->id);
    expect($invite->business_id)->not->toBe($businessB->id);
});

it('never lets accepting an expired invite create a membership', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();

    $invite = \App\Models\Invite::create([
        'business_id' => $businessA->id,
        'role' => 'salesman',
        'token' => 'expired-token-123',
        'invited_by' => $ownerA->id,
        'expires_at' => now()->subDay(),
    ]);

    $newUser = User::factory()->create();
    $newUserToken = (new TokenService())->issue($newUser);

    $this->withHeader('Authorization', "Bearer {$newUserToken}")
        ->postJson('/api/v1/invites/accept', ['token' => 'expired-token-123'])
        ->assertStatus(422);

    expect(Membership::on('pgsql_migrate')->where('user_id', $newUser->id)->exists())->toBeFalse();
});

it('never returns business Bs catalog to business A', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $userA = User::factory()->create();
    $membershipA = Membership::on('pgsql_migrate')->create([
        'user_id' => $userA->id, 'business_id' => $businessA->id, 'role' => 'owner',
    ]);

    Product::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'name_hi' => 'हल्दी', 'name_en' => 'Haldi',
    ]);

    $token = (new TokenService())->issue($userA, $membershipA);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(0, 'products');
});

it('rejects business As owner reading business Bs product by id', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $userA = User::factory()->create();
    $membershipA = Membership::on('pgsql_migrate')->create([
        'user_id' => $userA->id, 'business_id' => $businessA->id, 'role' => 'owner',
    ]);

    $foreign = Product::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'name_hi' => 'हल्दी',
    ]);

    $token = (new TokenService())->issue($userA, $membershipA);

    // 404, not 403: a 403 would confirm the row exists, leaking that a
    // competitor's product id is real.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$foreign->id}", ['name_en' => 'Stolen'])
        ->assertStatus(404);
});

it('rejects business As owner archiving business Bs product', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $userA = User::factory()->create();
    $membershipA = Membership::on('pgsql_migrate')->create([
        'user_id' => $userA->id, 'business_id' => $businessA->id, 'role' => 'owner',
    ]);

    $foreign = Product::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'name_hi' => 'हल्दी',
    ]);

    $token = (new TokenService())->issue($userA, $membershipA);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$foreign->id}")
        ->assertStatus(404);

    // And it really is untouched. withoutGlobalScopes() because the request
    // above bound app('tenant.id') to business A for the rest of the process;
    // BelongsToTenant's scope would otherwise filter this business-B row out and
    // the read would see null — hiding, not proving, that the row is intact.
    expect(Product::on('pgsql_migrate')->withoutGlobalScopes()->find($foreign->id)->archived_at)->toBeNull();
});

it('never returns business Bs khata to business A', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    Customer::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'uuid' => (string) Str::uuid(), 'name' => 'Their Customer',
    ]);

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/khata')
        ->assertOk()
        ->assertJsonCount(0, 'customers');
});

it('rejects business As owner posting a sale for business Bs customer', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    // A pack in A's own catalog, so only the customer is cross-tenant.
    $product = \App\Models\Product::on('pgsql_migrate')->create(['business_id' => $businessA->id, 'name_hi' => 'सेव']);
    $packSize = \App\Models\PackSize::on('pgsql_migrate')->create(['business_id' => $businessA->id, 'label' => '500g', 'weight_kg' => '0.500']);
    $pack = \App\Models\ProductPack::on('pgsql_migrate')->create([
        'business_id' => $businessA->id, 'product_id' => $product->id,
        'pack_size_id' => $packSize->id, 'default_sell_price' => '90.00',
    ]);

    $foreignCustomer = Customer::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'uuid' => (string) Str::uuid(), 'name' => 'Their Customer',
    ]);

    // 404, not 403: RLS hides B's customer, so findOrFail genuinely finds nothing.
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/sales', [
            'uuid' => (string) Str::uuid(), 'customer_id' => $foreignCustomer->id,
            'sale_date' => '2026-07-17', 'lines' => [['product_pack_id' => $pack->id, 'qty' => 1]],
        ])
        ->assertStatus(404);
});

it('rejects business As owner voiding business Bs sale and leaves it untouched', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $customerB = Customer::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'uuid' => (string) Str::uuid(), 'name' => 'Their Customer',
    ]);
    $saleB = new Sale([
        'business_id' => $businessB->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customerB->id, 'sale_date' => '2026-07-17',
    ]);
    $saleB->setConnection('pgsql_migrate');
    $saleB->created_by = $ownerB->id;
    $saleB->total = '90.00';
    $saleB->save();

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/sales/{$saleB->id}/void")
        ->assertStatus(404);

    // withoutGlobalScopes(): the request pinned app('tenant.id') to A, so a scoped
    // read of this business-B row would see null and prove nothing.
    $fresh = Sale::on('pgsql_migrate')->withoutGlobalScopes()->find($saleB->id);
    expect($fresh->reverses_id)->toBeNull();
    expect(Sale::on('pgsql_migrate')->withoutGlobalScopes()->where('reverses_id', $saleB->id)->exists())->toBeFalse();
});

it('rejects a sync push mutation stamped with business Bs tenant_id and writes nothing', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $customerA = Customer::on('pgsql_migrate')->create([
        'business_id' => $businessA->id, 'uuid' => (string) Str::uuid(), 'name' => 'My Customer',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/sync/push', ['mutations' => [[
            'type' => 'payment',
            'tenant_id' => $businessB->id, // A's session, B's tenant stamp
            'uuid' => (string) Str::uuid(),
            'payload' => ['customer_id' => $customerA->id, 'payment_date' => '2026-07-17', 'amount' => '100.00', 'mode' => 'cash'],
        ]]])
        ->assertOk();

    expect($response->json('results.0.status'))->toBe('rejected');
    expect($response->json('results.0.reason'))->toBe('tenant_mismatch');
    expect(\App\Models\Payment::on('pgsql_migrate')->count())->toBe(0);
});

it('never returns business Bs stock to business A', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    \App\Models\RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Their Besan', 'unit' => 'kg', 'reorder_level' => '10.000',
    ]);

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/stock')
        ->assertOk()
        ->assertJsonCount(0, 'materials');
});

it('rejects business As owner recording a movement for business Bs material', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $foreignMaterial = \App\Models\RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Theirs', 'unit' => 'kg',
    ]);

    // 404, not 403: RLS hides B's material, so findOrFail genuinely finds nothing.
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $foreignMaterial->id,
            'movement_date' => '2026-07-17', 'kind' => 'in', 'qty' => '10.000',
        ])
        ->assertStatus(404);

    // And no movement leaked onto B's material. withoutGlobalScopes(): the request
    // pinned app('tenant.id') to A, so a scoped read would filter this B row out.
    expect(\App\Models\StockMovement::on('pgsql_migrate')->withoutGlobalScopes()
        ->where('raw_material_id', $foreignMaterial->id)->count())->toBe(0);
});

it('rejects business As batch consuming business Bs material and leaves Bs stock untouched', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    // A's own product (only the consumed material is cross-tenant).
    $product = Product::on('pgsql_migrate')->create(['business_id' => $businessA->id, 'name_hi' => 'सेव']);

    $foreignMaterial = \App\Models\RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Theirs', 'unit' => 'kg',
    ]);
    // Seed B's stock so we can prove it is unchanged.
    $seed = new \App\Models\StockMovement([
        'business_id' => $businessB->id, 'uuid' => (string) Str::uuid(),
        'raw_material_id' => $foreignMaterial->id, 'movement_date' => '2026-07-01',
        'kind' => 'in', 'qty' => '100.000',
    ]);
    $seed->setConnection('pgsql_migrate');
    $seed->created_by = $ownerB->id;
    $seed->save();

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/production', [
            'uuid' => (string) Str::uuid(), 'product_id' => $product->id,
            'batch_date' => '2026-07-17', 'output_kg' => '30.000',
            'consumptions' => [['raw_material_id' => $foreignMaterial->id, 'qty' => '25.000']],
        ])
        ->assertStatus(404);

    // B's stock is exactly the seeded 100 — no draw-down leaked, no batch created.
    // withoutGlobalScopes() because the request pinned the tenant to A.
    $onHand = (string) \App\Models\StockMovement::on('pgsql_migrate')->withoutGlobalScopes()
        ->where('raw_material_id', $foreignMaterial->id)
        ->selectRaw('coalesce(sum(qty), 0)::text as agg')->value('agg');
    expect($onHand)->toBe('100.000');
    expect(\App\Models\ProductionBatch::on('pgsql_migrate')->withoutGlobalScopes()
        ->where('business_id', $businessA->id)->count())->toBe(0);
});

it('never shows business Bs subscription payments in business As billing', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    // A needs its own subscription for the billing endpoint to resolve.
    Subscription::on('pgsql_migrate')->create([
        'business_id' => $businessA->id, 'plan' => 'free',
        'status' => 'trialing', 'trial_ends_at' => now()->addDays(14),
    ]);
    $aPayment = SubscriptionPayment::on('pgsql_migrate')->create([
        'business_id' => $businessA->id, 'uuid' => (string) Str::uuid(),
        'plan' => 'pro', 'amount' => '499.00', 'gst_amount' => '89.82',
        'mode' => 'upi', 'period_months' => 1, 'status' => 'pending',
    ]);
    $bPayment = SubscriptionPayment::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'uuid' => (string) Str::uuid(),
        'plan' => 'pro', 'amount' => '999.00', 'gst_amount' => '179.82',
        'mode' => 'upi', 'period_months' => 1, 'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/billing')
        ->assertOk();

    $ids = collect($response->json('payments'))->pluck('id');
    expect($ids)->toContain($aPayment->id);
    expect($ids)->not->toContain($bPayment->id);
});

it('stamps a recorded payment with the callers tenant, ignoring a supplied business_id', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    Subscription::on('pgsql_migrate')->create([
        'business_id' => $businessA->id, 'plan' => 'free',
        'status' => 'trialing', 'trial_ends_at' => now()->addDays(14),
    ]);

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/billing/payments', [
            'business_id' => $businessB->id, // ignored — never trusted from the payload
            'plan' => 'pro', 'amount' => '499.00', 'mode' => 'upi', 'period_months' => 1,
        ])
        ->assertCreated();

    $payment = SubscriptionPayment::on('pgsql_migrate')->withoutGlobalScopes()
        ->latest('created_at')->first();
    expect($payment->business_id)->toBe($businessA->id);
    expect($payment->business_id)->not->toBe($businessB->id);
});

it('never lets business Bs read_only status gate business As writes', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    // B is in dunning (read_only); A carries on normally on its own fail-open trial.
    Subscription::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'plan' => 'pro', 'status' => 'read_only',
        'trial_ends_at' => now()->subDays(30), 'current_period_end' => now()->subDay(),
    ]);

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/customers', ['name' => 'A Customer'])
        ->assertCreated();
});
