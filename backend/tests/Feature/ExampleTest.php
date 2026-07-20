<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `/` no longer renders Laravel's welcome page — it routes the visitor into
     * the app (see HealthCheckTest for the fuller coverage of that behaviour).
     */
    public function test_the_application_responds_at_the_root(): void
    {
        $this->get('/')->assertRedirect(route('app'));
    }
}
