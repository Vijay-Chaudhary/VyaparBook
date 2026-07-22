<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Blade feature tests render @vite layouts, but the backend CI job runs
        // Pest without building the frontend — so no manifest exists and every
        // such view would 500. No test here asserts on real bundled assets, so
        // stub Vite: @vite renders nothing and the suite is asset-independent.
        $this->withoutVite();
    }
}
