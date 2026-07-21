<?php
// tests/Unit/HasSyncSequenceTraitTest.php

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
    // No RLS on the fixture table: the model writes on the default pgsql
    // connection (the restricted role), so this also proves that role can call
    // nextval() on sync_seq_global — the grant path the trait depends on.
    Schema::connection('pgsql_migrate')->create('sync_fixture_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->bigInteger('sync_seq')->nullable();
    });
});

afterEach(function () {
    Schema::connection('pgsql_migrate')->dropIfExists('sync_fixture_items');
});

it('stamps a positive sync_seq on insert', function () {
    $item = SyncFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    expect($item->fresh()->sync_seq)->toBeInt()->toBeGreaterThan(0);
});

it('stamps a strictly greater sync_seq on each successive insert', function () {
    $first = SyncFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);
    $second = SyncFixtureItem::create(['id' => Str::uuid(), 'name' => 'Mix']);

    expect($second->fresh()->sync_seq)->toBeGreaterThan($first->fresh()->sync_seq);
});

it('advances sync_seq again on update', function () {
    $item = SyncFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);
    $afterInsert = $item->fresh()->sync_seq;

    $item->update(['name' => 'Sev Special']);

    expect($item->fresh()->sync_seq)->toBeGreaterThan($afterInsert);
});
