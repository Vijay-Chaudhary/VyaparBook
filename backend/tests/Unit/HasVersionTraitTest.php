<?php
// tests/Unit/HasVersionTraitTest.php

use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VersionFixtureItem extends Model
{
    use HasVersion;

    protected $table = 'version_fixture_items';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
}

beforeEach(function () {
    Schema::create('version_fixture_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->unsignedInteger('version')->default(1);
    });
});

afterEach(function () {
    Schema::dropIfExists('version_fixture_items');
});

it('starts at version 1', function () {
    $item = VersionFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    expect($item->fresh()->version)->toBe(1);
});

it('bumps the version on update', function () {
    $item = VersionFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    $item->update(['name' => 'Sev Special']);

    expect($item->fresh()->version)->toBe(2);
});

it('does not bump the version on a read', function () {
    $item = VersionFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    VersionFixtureItem::find($item->id);

    expect($item->fresh()->version)->toBe(1);
});
