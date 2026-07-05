<?php
// tests/Unit/UserModelTest.php

use App\Models\User;

it('has a unique phone number', function () {
    $user = User::factory()->create();

    expect($user->phone)->toMatch('/^9\d{9}$/');
});
