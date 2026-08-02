<?php
// tests/Feature/Stock/StockMovementTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Services\TokenService;
use Illuminate\Support\Str;

function movementToken(Business $business, string $role = 'owner'): string
{
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

function movementMaterial(Business $business): RawMaterial
{
    return RawMaterial::create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Besan', 'unit' => 'kg', 'reorder_level' => '10.000',
    ]);
}

it('records an in movement that raises on hand', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(),
            'raw_material_id' => $material->id,
            'movement_date' => '2026-07-10',
            'kind' => 'in',
            'qty' => '100.000',
        ])
        ->assertStatus(201)
        ->assertJson(['kind' => 'in', 'qty' => '100.000']); // stored positive

    expect((new StockService())->onHandFor($material))->toBe('100.000');
});

it('stores an out movement as a negative that lowers on hand', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    // seed some stock directly (created_by is not fillable — set it explicitly)
    $seed = new StockMovement([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'raw_material_id' => $material->id, 'movement_date' => '2026-07-09',
        'kind' => 'in', 'qty' => '100.000',
    ]);
    $seed->created_by = User::factory()->create()->id;
    $seed->save();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(),
            'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11',
            'kind' => 'out',
            'qty' => '30.000', // positive magnitude in
        ])
        ->assertStatus(201);

    expect($response->json('qty'))->toBe('-30.000'); // sign derived from kind
    expect((new StockService())->onHandFor($material))->toBe('70.000');
});

it('applies a signed adjust delta as given', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(),
            'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11',
            'kind' => 'adjust',
            'qty' => '-2.500', // a recount correction, negative
        ])
        ->assertStatus(201)
        ->assertJson(['qty' => '-2.500']);

    expect((new StockService())->onHandFor($material))->toBe('-2.500');
});

it('allows on hand to go negative through over-consumption', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'out', 'qty' => '5.000',
        ])
        ->assertStatus(201);

    expect((new StockService())->onHandFor($material))->toBe('-5.000');
});

it('replays the same movement on a repeated uuid', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);
    $uuid = (string) Str::uuid();

    $first = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => $uuid, 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'in', 'qty' => '40.000',
        ])->assertStatus(201);

    $second = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => $uuid, 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'in', 'qty' => '40.000',
        ])->assertStatus(200); // replay

    expect($second->json('id'))->toBe($first->json('id'));
    expect((new StockService())->onHandFor($material))->toBe('40.000'); // not doubled
});

it('rejects an out movement with a non-positive qty', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'out', 'qty' => '-5.000',
        ])
        ->assertStatus(422);
});

it('rejects a zero qty for any kind', function () {
    $business = Business::factory()->create();
    $token = movementToken($business);
    $material = movementMaterial($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'adjust', 'qty' => '0',
        ])
        ->assertStatus(422);
});

it('returns 404 recording a movement for another business material', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = movementToken($mine);
    $foreign = movementMaterial($theirs);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $foreign->id,
            'movement_date' => '2026-07-11', 'kind' => 'in', 'qty' => '10.000',
        ])
        ->assertStatus(404);
});

it('blocks a salesman from recording a movement', function () {
    $business = Business::factory()->create();
    $material = movementMaterial($business);

    $this->withHeader('Authorization', 'Bearer ' . movementToken($business, 'salesman'))
        ->postJson('/api/v1/stock-movements', [
            'uuid' => (string) Str::uuid(), 'raw_material_id' => $material->id,
            'movement_date' => '2026-07-11', 'kind' => 'in', 'qty' => '10.000',
        ])
        ->assertStatus(403);
});

describe('reversing a movement', function () {
    /** Records one movement of $kind and returns [business, token, material, id]. */
    function recordedMovement(string $kind = 'in', string $qty = '50.000'): array
    {
        $business = Business::factory()->create();
        $token = movementToken($business);
        $material = movementMaterial($business);

        $id = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/stock-movements', [
                'uuid' => (string) Str::uuid(),
                'raw_material_id' => $material->id,
                'movement_date' => '2026-07-15',
                'kind' => $kind,
                'qty' => $qty,
            ])->assertStatus(201)->json('id');

        return [$business, $token, $material, $id];
    }

    it('cancels the movement, leaving on-hand where it started', function () {
        [, $token, $material, $id] = recordedMovement('in', '50.000');
        expect((new StockService())->onHandFor($material))->toBe('50.000');

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/stock-movements/{$id}/reverse")->assertStatus(201);

        expect((new StockService())->onHandFor($material))->toBe('0.000');
    });

    it('flips the kind, so no row contradicts its own sign', function () {
        // The schema's invariant is that an `out` can never raise stock.
        // Copying the kind onto a negated qty would store exactly that.
        [, $token, , $id] = recordedMovement('in', '50.000');

        $reversal = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/stock-movements/{$id}/reverse")->json();

        expect($reversal['kind'])->toBe('out');
        expect((string) $reversal['qty'])->toBe('-50.000');
    });

    it('reverses an out movement back into an in', function () {
        [, $token, $material, $id] = recordedMovement('out', '20.000');
        expect((new StockService())->onHandFor($material))->toBe('-20.000');

        $reversal = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/stock-movements/{$id}/reverse")->json();

        expect($reversal['kind'])->toBe('in');
        expect((new StockService())->onHandFor($material))->toBe('0.000');
    });

    it('leaves the original untouched', function () {
        [, $token, , $id] = recordedMovement('in', '50.000');

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/stock-movements/{$id}/reverse")->assertStatus(201);

        $original = StockMovement::find($id);
        expect((string) $original->qty)->toBe('50.000');
        expect($original->reverses_id)->toBeNull();
    });

    it('refuses a movement that came from a production batch', function () {
        // Reversing it alone would leave the batch's consumption record
        // disagreeing with stock. The batch is the thing to reverse.
        $business = Business::factory()->create();
        $user = User::factory()->create();
        $token = movementToken($business);
        $material = movementMaterial($business);

        $seed = new StockMovement([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'raw_material_id' => $material->id, 'movement_date' => '2026-07-01',
            'kind' => 'in', 'qty' => '100.000',
        ]);
        $seed->created_by = $user->id;
        $seed->save();

        $product = App\Models\Product::create([
            'business_id' => $business->id, 'name_hi' => 'सेव',
        ]);

        test()->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/production', [
            'uuid' => (string) Str::uuid(), 'product_id' => $product->id,
            'batch_date' => '2026-07-15', 'output_kg' => '30.000',
            'consumptions' => [['raw_material_id' => $material->id, 'qty' => '25.000']],
        ])->assertStatus(201);

        $drawDown = StockMovement::whereNotNull('production_batch_id')->firstOrFail();

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/stock-movements/{$drawDown->id}/reverse")
            ->assertStatus(422)
            ->assertJsonPath('message', __('stock.reverse_the_batch'));
    });

    it('409s on a double reverse', function () {
        [, $token, , $id] = recordedMovement();
        $url = "/api/v1/stock-movements/{$id}/reverse";

        test()->withHeader('Authorization', "Bearer {$token}")->postJson($url)->assertStatus(201);
        test()->withHeader('Authorization', "Bearer {$token}")->postJson($url)->assertStatus(409);
    });

    it('422s reversing a reversal', function () {
        [, $token, , $id] = recordedMovement();

        $reversalId = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/stock-movements/{$id}/reverse")->json('id');

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/stock-movements/{$reversalId}/reverse")->assertStatus(422);
    });

    it('refuses a salesman', function () {
        [$business, , , $id] = recordedMovement();
        $salesToken = movementToken($business, 'salesman');

        test()->withHeader('Authorization', "Bearer {$salesToken}")
            ->postJson("/api/v1/stock-movements/{$id}/reverse")->assertStatus(403);
    });

    it('404s on another tenant\'s movement', function () {
        [, $token] = recordedMovement();
        [, , , $theirId] = recordedMovement();

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/stock-movements/{$theirId}/reverse")->assertStatus(404);
    });
});
