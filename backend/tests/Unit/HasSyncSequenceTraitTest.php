<?php
// tests/Unit/HasSyncSequenceTraitTest.php

use App\Models\Business;
use App\Traits\HasSyncSequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SyncFixtureItem extends Model
{
    use HasSyncSequence;

    protected $table = 'sync_fixture_items';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
}

beforeEach(function () {
    // The fixture carries business_id because the counter is per-tenant now:
    // the trait draws from sync_sequences keyed by the row's own tenant. It
    // deliberately does NOT use BelongsToTenant, so this still proves the trait
    // works on its own rather than by way of the tenant scope.
    Schema::create('sync_fixture_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
        $table->string('name');
        $table->bigInteger('sync_seq')->nullable();
    });

    $this->business = Business::factory()->create();
});

afterEach(function () {
    Schema::dropIfExists('sync_fixture_items');
});

/** A fixture row belonging to the test's business. */
function syncItem(string $businessId, string $name): SyncFixtureItem
{
    return SyncFixtureItem::create([
        'id' => Str::uuid(), 'business_id' => $businessId, 'name' => $name,
    ]);
}

it('stamps a positive sync_seq on insert', function () {
    $item = syncItem($this->business->id, 'Sev');

    expect($item->fresh()->sync_seq)->toBeInt()->toBeGreaterThan(0);
});

it('stamps a strictly greater sync_seq on each successive insert', function () {
    $first = syncItem($this->business->id, 'Sev');
    $second = syncItem($this->business->id, 'Mix');

    expect($second->fresh()->sync_seq)->toBeGreaterThan($first->fresh()->sync_seq);
});

it('advances sync_seq again on update', function () {
    $item = syncItem($this->business->id, 'Sev');
    $afterInsert = $item->fresh()->sync_seq;

    $item->update(['name' => 'Sev Special']);

    expect($item->fresh()->sync_seq)->toBeGreaterThan($afterInsert);
});

it('refuses to draw a sequence with no tenant to draw it for', function () {
    // Every synced row belongs to exactly one business. A model with neither a
    // business_id nor a bound tenant would otherwise silently take another
    // shop's number.
    expect(fn () => SyncFixtureItem::create(['id' => Str::uuid(), 'name' => 'Orphan']))
        ->toThrow(RuntimeException::class, 'without a tenant');
});
