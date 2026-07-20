<?php


it('issues a token after verifying a correct otp', function () {
    $phone = '9876543210';

    $requestResponse = $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertOk();
    $code = $requestResponse->json('debug_code');

    // First verify for this phone is a signup, so it carries DPDP consent.
    $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => $code, 'consent' => true])
        ->assertOk()
        ->assertJsonStructure(['token']);
});

it('rejects an incorrect otp', function () {
    $phone = '9876543211';
    $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone]);

    $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => '000000'])
        ->assertStatus(422);
});

it('rate limits repeated otp requests for the same phone', function () {
    $phone = '9876543212';

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertOk();
    }

    $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertStatus(429);
});
