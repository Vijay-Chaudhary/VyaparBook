<?php
// tests/Feature/Web/BeatsTest.php

use App\Models\Beat;
use App\Models\BeatCustomer;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function beatCustomer(string $businessId, string $name): Customer
{
    return Customer::on('pgsql_migrate')->create([
        'business_id' => $businessId, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'village' => 'Rampur', 'opening_balance' => '0.00',
    ]);
}

describe('access', function () {
    it('redirects a guest to login', function () {
        $this->get('/beats')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())->get('/beats')->assertRedirect(route('app'));
    });
});

describe('planning', function () {
    it('creates a beat with its weekdays', function () {
        [$owner, $business] = pwOwner();

        $this->actingAs($owner)->post('/beats', [
            'business' => $business->id, 'name' => 'Rampur', 'weekdays' => [1, 4],
        ])->assertRedirect(route('beats', ['business' => $business->id]));

        $beat = Beat::on('pgsql_migrate')->where('business_id', $business->id)->sole();
        expect($beat->name)->toBe('Rampur');
        expect($beat->weekdays)->toBe([1, 4]);
    });

    it('requires at least one day, since a beat that never runs is not a plan', function () {
        [$owner, $business] = pwOwner();

        $this->actingAs($owner)->post('/beats', [
            'business' => $business->id, 'name' => 'Nowhere', 'weekdays' => [],
        ])->assertSessionHasErrors('weekdays');
    });

    it('refuses to assign someone who is not in the business', function () {
        [$owner, $business] = pwOwner();
        $stranger = User::factory()->create();

        $this->actingAs($owner)->post('/beats', [
            'business' => $business->id, 'name' => 'Rampur',
            'weekdays' => [1], 'assigned_user_id' => $stranger->id,
        ]);

        // Silently unassigned rather than accepted: membership is checked, not trusted.
        expect(Beat::on('pgsql_migrate')->where('business_id', $business->id)->sole()->assigned_user_id)->toBeNull();
    });

    it('sets the customer list in call order', function () {
        [$owner, $business] = pwOwner();
        $first = beatCustomer($business->id, 'First');
        $second = beatCustomer($business->id, 'Second');

        $this->actingAs($owner)->post('/beats', [
            'business' => $business->id, 'name' => 'Rampur', 'weekdays' => [1],
        ]);
        $beat = Beat::on('pgsql_migrate')->where('business_id', $business->id)->sole();

        $this->actingAs($owner)->post('/beats/' . $beat->id . '/customers', [
            'business' => $business->id, 'customers' => [$second->id, $first->id],
        ])->assertRedirect();

        $rows = DB::connection('pgsql_migrate')->table('beat_customers')
            ->where('beat_id', $beat->id)->orderBy('position')->get();

        expect($rows->pluck('customer_id')->all())->toBe([$second->id, $first->id]);
        expect($rows->pluck('position')->all())->toBe([1, 2]);
    });

    it('replaces the list rather than accumulating stale rows', function () {
        [$owner, $business] = pwOwner();
        $a = beatCustomer($business->id, 'A');
        $b = beatCustomer($business->id, 'B');

        $this->actingAs($owner)->post('/beats', [
            'business' => $business->id, 'name' => 'Rampur', 'weekdays' => [1],
        ]);
        $beat = Beat::on('pgsql_migrate')->where('business_id', $business->id)->sole();

        $this->actingAs($owner)->post('/beats/' . $beat->id . '/customers', [
            'business' => $business->id, 'customers' => [$a->id, $b->id],
        ]);
        $this->actingAs($owner)->post('/beats/' . $beat->id . '/customers', [
            'business' => $business->id, 'customers' => [$b->id],
        ]);

        $rows = DB::connection('pgsql_migrate')->table('beat_customers')->where('beat_id', $beat->id)->get();
        expect($rows)->toHaveCount(1);
        expect($rows->first()->customer_id)->toBe($b->id);
    });

    it('does not accept another tenant\'s customer onto a beat', function () {
        [$owner, $business] = pwOwner();
        [, $other] = pwOwner();
        $theirCustomer = beatCustomer($other->id, 'Not Yours');

        $this->actingAs($owner)->post('/beats', [
            'business' => $business->id, 'name' => 'Rampur', 'weekdays' => [1],
        ]);
        $beat = Beat::on('pgsql_migrate')->where('business_id', $business->id)->sole();

        $this->actingAs($owner)->post('/beats/' . $beat->id . '/customers', [
            'business' => $business->id, 'customers' => [$theirCustomer->id],
        ]);

        expect(DB::connection('pgsql_migrate')->table('beat_customers')->where('beat_id', $beat->id)->count())->toBe(0);
    });

    it('does not touch another tenant\'s beat', function () {
        [$owner, $business] = pwOwner();
        [$otherOwner, $other] = pwOwner();
        $this->actingAs($otherOwner)->post('/beats', [
            'business' => $other->id, 'name' => 'Theirs', 'weekdays' => [1],
        ]);
        $theirs = Beat::on('pgsql_migrate')->where('business_id', $other->id)->sole();

        $this->actingAs($owner)->post('/beats/' . $theirs->id . '/archive', ['business' => $business->id])
            ->assertNotFound();

        expect(DB::connection('pgsql_migrate')->table('beats')->where('id', $theirs->id)->value('archived_at'))->toBeNull();
    });

    it('archives a beat instead of deleting it, so devices learn to drop it', function () {
        [$owner, $business] = pwOwner();
        $this->actingAs($owner)->post('/beats', [
            'business' => $business->id, 'name' => 'Rampur', 'weekdays' => [1],
        ]);
        $beat = Beat::on('pgsql_migrate')->where('business_id', $business->id)->sole();

        $this->actingAs($owner)->post('/beats/' . $beat->id . '/archive', ['business' => $business->id]);

        $row = DB::connection('pgsql_migrate')->table('beats')->where('id', $beat->id)->first();
        expect($row)->not->toBeNull();              // still there to sync
        expect($row->archived_at)->not->toBeNull();
    });

    it('renders the beat list and flags what runs today', function () {
        [$owner, $business] = pwOwner();
        $this->actingAs($owner)->post('/beats', [
            'business' => $business->id, 'name' => 'Rampur',
            'weekdays' => [(int) now()->isoWeekday()],
        ]);

        $this->actingAs($owner)->get('/beats?business=' . $business->id)
            ->assertOk()
            ->assertSee('Rampur')
            ->assertSee(__('beats.runs_today'));
    });
});
