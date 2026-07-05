<?php
// tests/Feature/HealthCheckTest.php

it('responds to the root route', function () {
    $this->get('/')->assertStatus(200);
});
