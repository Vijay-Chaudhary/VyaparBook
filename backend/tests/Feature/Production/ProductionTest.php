<?php
// tests/Feature/Production/ProductionTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Services\TokenService;
use Illuminate\Support\Str;

function productionToken(Business $business, string $role = 'owner'): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

function productionProduct(Business $business): Product
{
    return Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव',
    ]);
}

function productionMaterial(Business $business, string $name = 'Besan'): RawMaterial
{
    return RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'unit' => 'kg', 'reorder_level' => '10.000',
    ]);
}

function seedStock(RawMaterial $m, User $u, string $qty): void
{
    $movement = new StockMovement([
        'business_id' => $m->business_id, 'uuid' => (string) Str::uuid(),
        'raw_material_id' => $m->id, 'movement_date' => '2026-07-01', 'kind' => 'in', 'qty' => $qty,
    ]);
    $movement->setConnection('pgsql_migrate');
    $movement->created_by = $u->id;
    $movement->save();
}

it('creates a batch that records consumptions and draws stock down', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $token = productionToken($business);
    $product = productionProduct($business);
    $besan = productionMaterial($business, 'Besan');
    $oil = productionMaterial($business, 'Oil');

    seedStock($besan, $user, '100.000');
    seedStock($oil, $user, '40.000');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/production', [
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->id,
            'batch_date' => '2026-07-15',
            'output_kg' => '30.000',
            'consumptions' => [
                ['raw_material_id' => $besan->id, 'qty' => '25.000'],
                ['raw_material_id' => $oil->id, 'qty' => '8.000'],
            ],
        ])
        ->assertStatus(201);

    $stock = new StockService();
    expect($stock->onHandFor($besan))->toBe('75.000'); // 100 - 25
    expect($stock->onHandFor($oil))->toBe('32.000');   // 40 - 8

    // consumptions recorded on the batch
    $batch = ProductionBatch::on('pgsql_migrate')->find($response->json('id'));
    expect($batch->consumptions()->count())->toBe(2);

    // the out movements carry the batch id (traceable draw-down)
    $tagged = StockMovement::on('pgsql_migrate')
        ->where('production_batch_id', $batch->id)->get();
    expect($tagged)->toHaveCount(2);
    expect($tagged->every(fn ($m) => $m->kind === 'out'))->toBeTrue();
});

it('replays a batch on a repeated uuid without a second draw-down', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $token = productionToken($business);
    $product = productionProduct($business);
    $besan = productionMaterial($business);
    seedStock($besan, $user, '100.000');
    $uuid = (string) Str::uuid();

    $payload = [
        'uuid' => $uuid, 'product_id' => $product->id, 'batch_date' => '2026-07-15',
        'output_kg' => '30.000',
        'consumptions' => [['raw_material_id' => $besan->id, 'qty' => '25.000']],
    ];

    $first = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/production', $payload)->assertStatus(201);
    $second = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/production', $payload)->assertStatus(200); // replay

    expect($second->json('id'))->toBe($first->json('id'));
    expect((new StockService())->onHandFor($besan))->toBe('75.000'); // drawn down once, not twice
    expect(ProductionBatch::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);
});

it('lets over-consumption drive on hand negative', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $token = productionToken($business);
    $product = productionProduct($business);
    $besan = productionMaterial($business);
    seedStock($besan, $user, '10.000');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/production', [
            'uuid' => (string) Str::uuid(), 'product_id' => $product->id,
            'batch_date' => '2026-07-15', 'output_kg' => '30.000',
            'consumptions' => [['raw_material_id' => $besan->id, 'qty' => '25.000']],
        ])
        ->assertStatus(201);

    expect((new StockService())->onHandFor($besan))->toBe('-15.000'); // 10 - 25
});

it('returns 404 consuming another business material', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = productionToken($mine);
    $product = productionProduct($mine);
    $foreign = productionMaterial($theirs);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/production', [
            'uuid' => (string) Str::uuid(), 'product_id' => $product->id,
            'batch_date' => '2026-07-15', 'output_kg' => '30.000',
            'consumptions' => [['raw_material_id' => $foreign->id, 'qty' => '5.000']],
        ])
        ->assertStatus(404);
});

it('returns 404 producing another business product', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = productionToken($mine);
    $foreignProduct = productionProduct($theirs);
    $besan = productionMaterial($mine);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/production', [
            'uuid' => (string) Str::uuid(), 'product_id' => $foreignProduct->id,
            'batch_date' => '2026-07-15', 'output_kg' => '30.000',
            'consumptions' => [['raw_material_id' => $besan->id, 'qty' => '5.000']],
        ])
        ->assertStatus(404);
});

it('blocks a salesman from creating a batch', function () {
    $business = Business::factory()->create();
    $product = productionProduct($business);
    $besan = productionMaterial($business);

    $this->withHeader('Authorization', 'Bearer ' . productionToken($business, 'salesman'))
        ->postJson('/api/v1/production', [
            'uuid' => (string) Str::uuid(), 'product_id' => $product->id,
            'batch_date' => '2026-07-15', 'output_kg' => '30.000',
            'consumptions' => [['raw_material_id' => $besan->id, 'qty' => '5.000']],
        ])
        ->assertStatus(403);
});

it('lists the tenant batches newest first', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $token = productionToken($business);
    $product = productionProduct($business);
    $besan = productionMaterial($business);
    seedStock($besan, $user, '500.000');

    foreach (['2026-07-10', '2026-07-15'] as $date) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/production', [
                'uuid' => (string) Str::uuid(), 'product_id' => $product->id,
                'batch_date' => $date, 'output_kg' => '30.000',
                'consumptions' => [['raw_material_id' => $besan->id, 'qty' => '5.000']],
            ])->assertStatus(201);
    }

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/production')->assertOk();

    $dates = collect($response->json('batches'))->pluck('batch_date')->all();
    expect($dates[0])->toStartWith('2026-07-15'); // newest first
    expect($response->json('batches'))->toHaveCount(2);
});

it('shows a batch with its consumptions', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $token = productionToken($business);
    $product = productionProduct($business);
    $besan = productionMaterial($business, 'Besan');
    seedStock($besan, $user, '100.000');

    $created = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/production', [
            'uuid' => (string) Str::uuid(), 'product_id' => $product->id,
            'batch_date' => '2026-07-15', 'output_kg' => '30.000',
            'consumptions' => [['raw_material_id' => $besan->id, 'qty' => '25.000']],
        ])->assertStatus(201);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/production/{$created->json('id')}")->assertOk();

    expect($response->json('consumptions'))->toHaveCount(1);
    expect($response->json('consumptions.0.raw_material_name'))->toBe('Besan');
    expect($response->json('consumptions.0.qty'))->toBe('25.000');
});

it('returns 404 showing a batch in another business', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = productionToken($mine);

    $foreign = new ProductionBatch([
        'business_id' => $theirs->id, 'uuid' => (string) Str::uuid(),
        'product_id' => productionProduct($theirs)->id,
        'batch_date' => '2026-07-15', 'output_kg' => '30.000',
    ]);
    $foreign->setConnection('pgsql_migrate');
    $foreign->created_by = User::factory()->create()->id;
    $foreign->save();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/production/{$foreign->id}")
        ->assertStatus(404);
});

describe('reversing a batch', function () {
    /** A shop with a completed batch. Returns [business, user, token, batchId, besan, oil]. */
    function reversibleBatch(): array
    {
        $business = Business::factory()->create();
        $user = User::factory()->create();
        $token = productionToken($business);
        $product = productionProduct($business);
        $besan = productionMaterial($business, 'Besan');
        $oil = productionMaterial($business, 'Oil');

        seedStock($besan, $user, '100.000');
        seedStock($oil, $user, '40.000');

        $id = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/production', [
                'uuid' => (string) Str::uuid(),
                'product_id' => $product->id,
                'batch_date' => '2026-07-15',
                'output_kg' => '30.000',
                'consumptions' => [
                    ['raw_material_id' => $besan->id, 'qty' => '25.000'],
                    ['raw_material_id' => $oil->id, 'qty' => '8.000'],
                ],
            ])->assertStatus(201)->json('id');

        return [$business, $user, $token, $id, $besan, $oil];
    }

    it('puts the raw materials back AND negates the output', function () {
        // Both, because the batch did both. Negating only the output would
        // leave materials consumed by a batch that no longer counts.
        [$business, $user, $token, $batchId, $besan, $oil] = reversibleBatch();

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/production/{$batchId}/reverse")
            ->assertStatus(201);

        $stock = new StockService();
        expect($stock->onHandFor($besan))->toBe('100.000'); // 75 + 25 back
        expect($stock->onHandFor($oil))->toBe('40.000');    // 32 + 8 back

        // Finished goods nets to nothing: Σ output_kg across both batches.
        $totalOutput = (string) ProductionBatch::on('pgsql_migrate')
            ->where('business_id', $business->id)->sum('output_kg');
        expect(bccomp($totalOutput, '0', 3))->toBe(0);
    });

    it('leaves the original batch byte-for-byte intact', function () {
        [, , $token, $batchId] = reversibleBatch();

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/production/{$batchId}/reverse")->assertStatus(201);

        $original = ProductionBatch::on('pgsql_migrate')->find($batchId);
        expect((string) $original->output_kg)->toBe('30.000');
        expect($original->reverses_id)->toBeNull();
    });

    it('negates the consumptions too, so the COGS numerator falls in step', function () {
        // COGS is Σ(qty × ₹/kg) ÷ Σ output_kg. Negating output without
        // negating consumption would leave the rate wrong for every batch.
        [$business, , $token, $batchId] = reversibleBatch();

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/production/{$batchId}/reverse")->assertStatus(201);

        $total = (string) App\Models\MaterialConsumption::on('pgsql_migrate')
            ->where('business_id', $business->id)->sum('qty');
        expect(bccomp($total, '0', 3))->toBe(0);
    });

    it('dates the correction today rather than backdating it', function () {
        // Backdating would rewrite a past month's production chart, which is
        // exactly what append-only correction exists to avoid.
        [, , $token, $batchId] = reversibleBatch();

        $reversalId = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/production/{$batchId}/reverse")->json('id');

        expect(ProductionBatch::on('pgsql_migrate')->find($reversalId)->batch_date->toDateString())
            ->toBe(now()->toDateString());
    });

    it('409s on a double reverse', function () {
        [, , $token, $batchId] = reversibleBatch();
        $url = "/api/v1/production/{$batchId}/reverse";

        test()->withHeader('Authorization', "Bearer {$token}")->postJson($url)->assertStatus(201);
        test()->withHeader('Authorization', "Bearer {$token}")->postJson($url)->assertStatus(409);
    });

    it('422s reversing a reversal, which would be a re-entry not a correction', function () {
        [, , $token, $batchId] = reversibleBatch();

        $reversalId = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/production/{$batchId}/reverse")->json('id');

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/production/{$reversalId}/reverse")->assertStatus(422);
    });

    it('refuses a salesman, as every other stock action does', function () {
        [$business, , , $batchId] = reversibleBatch();
        $salesToken = productionToken($business, 'salesman');

        test()->withHeader('Authorization', "Bearer {$salesToken}")
            ->postJson("/api/v1/production/{$batchId}/reverse")->assertStatus(403);
    });

    it('404s on another tenant\'s batch', function () {
        [, , $token] = reversibleBatch();
        [, , , $theirBatchId] = reversibleBatch();

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/production/{$theirBatchId}/reverse")->assertStatus(404);
    });
});
