<?php
// tests/Feature/Auth/ConsentTest.php

use App\Models\Consent;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\TokenService;

$register = fn (array $overrides = []) => test()->postJson('/api/v1/auth/register', array_merge([
    'name' => 'Vijay Kumar',
    'email' => 'consent-'.uniqid().'@example.com',
    'password' => 'password123',
    'consent' => true,
], $overrides));

$otpSignup = function (string $phone, array $extra = []) {
    $code = test()->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->json('debug_code');

    return test()->postJson('/api/v1/auth/otp/verify', array_merge(
        ['phone' => $phone, 'code' => $code], $extra
    ));
};

it('records consent at email signup with the policy version and evidence', function () use ($register) {
    $register()->assertCreated();

    $consent = Consent::on('pgsql_migrate')->first();

    expect($consent)->not->toBeNull();
    expect($consent->action)->toBe(Consent::GRANTED)
        ->and($consent->policy_version)->toBe(config('dpdp.policy_version'))
        // Evidence of the affirmative action, captured when it happened.
        ->and($consent->ip_address)->not->toBeNull();
});

it('refuses to create an account without consent', function () use ($register) {
    $register(['consent' => false])->assertStatus(422)->assertJsonValidationErrors('consent');
    $register(['consent' => null])->assertStatus(422)->assertJsonValidationErrors('consent');

    // Nothing was created — no account, no consent record.
    expect(User::on('pgsql_migrate')->count())->toBe(0);
    expect(Consent::on('pgsql_migrate')->count())->toBe(0);
});

it('refuses an omitted consent field rather than defaulting it', function () use ($register) {
    // Consent must be a clear affirmative action; the server must never supply
    // it on the caller's behalf.
    $register()->json(); // sanity: the happy path works
    $response = test()->postJson('/api/v1/auth/register', [
        'name' => 'No Consent',
        'email' => 'noconsent@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('consent');
});

it('records consent at otp signup', function () use ($otpSignup) {
    $otpSignup('9876500001', ['consent' => true])->assertOk();

    expect(Consent::on('pgsql_migrate')->count())->toBe(1);
    expect(Consent::on('pgsql_migrate')->first()->action)->toBe(Consent::GRANTED);
});

it('refuses otp signup without consent, without consuming the code', function () use ($otpSignup) {
    $phone = '9876500002';
    $code = test()->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->json('debug_code');

    test()->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => $code])
        ->assertStatus(422)->assertJsonValidationErrors('consent');

    expect(User::on('pgsql_migrate')->where('phone', $phone)->exists())->toBeFalse();

    // The code must still work: a validation failure should not cost the user
    // their OTP and force them to request another.
    test()->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => $code, 'consent' => true])
        ->assertOk();
});

it('does not re-prompt a returning user at otp login', function () use ($otpSignup) {
    $phone = '9876500003';
    $otpSignup($phone, ['consent' => true])->assertOk();

    // Second time is a login, not a signup — no consent field required.
    $otpSignup($phone)->assertOk();

    // And no second consent record was written.
    expect(Consent::on('pgsql_migrate')->count())->toBe(1);
});

it('reports current consent and history to the person', function () use ($register) {
    $register()->assertCreated();
    $user = User::on('pgsql_migrate')->first();
    $token = (new TokenService())->issue($user);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/consent')
        ->assertOk()
        ->assertJsonPath('consented', true)
        ->assertJsonPath('latest.action', Consent::GRANTED)
        ->assertJsonPath('current_policy_version', config('dpdp.policy_version'))
        ->assertJsonCount(1, 'history');
});

it('lets the person withdraw consent, and records it without deleting data', function () use ($register) {
    $register()->assertCreated();
    $user = User::on('pgsql_migrate')->first();
    $token = (new TokenService())->issue($user);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/consent/withdraw')
        ->assertOk()
        ->assertJsonPath('consented', false);

    expect(Consent::on('pgsql_migrate')->count())->toBe(2);

    // Withdrawal is recorded, never a deletion of the prior grant: the ledger
    // must still show that consent WAS given, and when.
    $actions = Consent::on('pgsql_migrate')->orderBy('created_at')->pluck('action')->all();
    expect($actions)->toBe([Consent::GRANTED, Consent::WITHDRAWN]);

    // The account and its data survive — erasure is a separate operator action.
    expect(User::on('pgsql_migrate')->find($user->id))->not->toBeNull();
});

it('refuses to withdraw twice', function () use ($register) {
    $register()->assertCreated();
    $user = User::on('pgsql_migrate')->first();
    $token = (new TokenService())->issue($user);

    $withdraw = fn () => test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/consent/withdraw');

    $withdraw()->assertOk();
    $withdraw()->assertStatus(409);

    expect(Consent::on('pgsql_migrate')->count())->toBe(2);
});

/**
 * Consent to a superseded notice is not consent to the current one — otherwise
 * a policy change would silently inherit agreement nobody actually gave.
 */
it('treats consent to a superseded policy version as stale', function () use ($register) {
    $register()->assertCreated();
    $user = User::on('pgsql_migrate')->first();

    expect(app(ConsentService::class)->hasCurrentConsent($user))->toBeTrue();

    config(['dpdp.policy_version' => '2027-01-01']);

    expect(app(ConsentService::class)->hasCurrentConsent($user))->toBeFalse();
});

it('is append-only: consent records cannot be edited or deleted', function () use ($register) {
    $register()->assertCreated();
    $consent = Consent::on('pgsql_migrate')->first();

    expect(fn () => $consent->update(['action' => Consent::WITHDRAWN]))
        ->toThrow(LogicException::class);
    expect(fn () => $consent->delete())->toThrow(LogicException::class);
});
