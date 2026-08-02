<?php
// tests/Feature/Web/ExpensesTest.php

use App\Models\Business;
use App\Models\Expense;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Str;

/** @return array{0: User, 1: Business} */
function expensesOwner(): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    Membership::create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => 'owner',
    ]);

    return [$user, $business];
}

/**
 * Seed an expense directly. created_by is stamped, not
 * fillable, so it must be set after construction — Expense::create(['created_by'
 * => ...]) would silently drop it and hit the NOT NULL constraint.
 */
function seedExpense(Business $b, User $u, array $attrs = []): Expense
{
    $e = new Expense(array_merge([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'category' => 'rent', 'amount' => '5000.00', 'spent_on' => '2026-07-01',
    ], $attrs));
    $e->created_by = $u->id;
    $e->save();

    return $e;
}

describe('access', function () {
    it('redirects a guest to login', function () {
        $this->get('/expenses')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())
            ->get('/expenses')->assertRedirect(route('app'));
    });
});

describe('crud', function () {
    it('records an expense that then appears in the list', function () {
        [$owner, $business] = expensesOwner();

        $this->actingAs($owner)->post('/expenses', [
            'business' => $business->id,
            'category' => 'rent', 'amount' => '5000', 'spent_on' => '2026-07-01', 'note' => null,
        ])->assertRedirect();

        $this->actingAs($owner)
            ->get('/expenses?business=' . $business->id . '&year=2026&month=7')
            ->assertOk()
            ->assertSee('₹5,000.00');

        expect(Expense::where('business_id', $business->id)->count())->toBe(1);
    });

    it('requires a note when the category is other', function () {
        [$owner, $business] = expensesOwner();

        $this->actingAs($owner)->post('/expenses', [
            'business' => $business->id,
            'category' => 'other', 'amount' => '100', 'spent_on' => '2026-07-01', 'note' => null,
        ])->assertSessionHasErrors('note');
    });

    it('rejects an unknown category and a non-positive amount', function () {
        [$owner, $business] = expensesOwner();

        $this->actingAs($owner)->post('/expenses', [
            'business' => $business->id, 'category' => 'groceries', 'amount' => '100', 'spent_on' => '2026-07-01',
        ])->assertSessionHasErrors('category');

        $this->actingAs($owner)->post('/expenses', [
            'business' => $business->id, 'category' => 'rent', 'amount' => '0', 'spent_on' => '2026-07-01',
        ])->assertSessionHasErrors('amount');
    });

    it('edits and archives an owned expense', function () {
        [$owner, $business] = expensesOwner();
        $e = seedExpense($business, $owner);

        // Edit.
        $this->actingAs($owner)->put('/expenses/' . $e->id, [
            'business' => $business->id,
            'category' => 'rent', 'amount' => '5500', 'spent_on' => '2026-07-01', 'note' => 'revised',
        ])->assertRedirect();
        expect(Expense::find($e->id)->amount)->toBe('5500.00');

        // Archive (soft delete). A plain scoped read: archiving only stamps
        // archived_at, and the row is this tenant's own, so the scope left bound
        // by the request is exactly the one that should see it.
        $this->actingAs($owner)->delete('/expenses/' . $e->id, ['business' => $business->id])
            ->assertRedirect();
        expect(Expense::find($e->id)->archived_at)->not->toBeNull();
    });

    it('refuses to touch another tenant\'s expense', function () {
        [$owner, $business] = expensesOwner();
        [$otherOwner, $other] = expensesOwner();
        $foreign = seedExpense($other, $otherOwner, ['amount' => '9999.00']);

        $this->actingAs($owner)->delete('/expenses/' . $foreign->id, ['business' => $business->id])
            ->assertRedirect();
        // Untouched. Deliberately cross-tenant -- the assertion is about the
        // OTHER shop's row, which this tenant cannot see -- so withoutTenant,
        // which is greppable and tells the tripwire it was meant.
        expect(Tenancy::withoutTenant(fn () => Expense::find($foreign->id)->archived_at))
            ->toBeNull();
    });

    it('rejects a malformed client-supplied uuid cleanly (not a 500)', function () {
        [$owner, $business] = expensesOwner();

        $this->actingAs($owner)->post('/expenses', [
            'business' => $business->id, 'uuid' => 'not-a-uuid',
            'category' => 'rent', 'amount' => '5000', 'spent_on' => '2026-07-01',
        ])->assertSessionHasErrors('uuid');

        // Validation rejected the request before a tenant was bound, so say
        // which shop this counts.
        expect(asTenant($business->id, fn () => Expense::count()))->toBe(0);
    });

    it('is idempotent on a replayed uuid', function () {
        [$owner, $business] = expensesOwner();
        $uuid = (string) Str::uuid();

        $payload = [
            'business' => $business->id, 'uuid' => $uuid,
            'category' => 'rent', 'amount' => '5000', 'spent_on' => '2026-07-01',
        ];
        $this->actingAs($owner)->post('/expenses', $payload);
        $this->actingAs($owner)->post('/expenses', $payload);   // replay

        expect(Expense::where('business_id', $business->id)->count())->toBe(1);
    });
});
