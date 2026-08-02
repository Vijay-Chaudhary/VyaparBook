<?php
// tests/Unit/TenantAwareJobTest.php

use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class FixtureTenantJob implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    public static ?string $observedContainerTenant = null;

    public function handleForTenant(): void
    {
        self::$observedContainerTenant = app('tenant.id');
    }
}

/**
 * A second test here used to assert the job set the Postgres GUC
 * `app.current_tenant`, reading it back with current_setting(). Deleted rather
 * than ported, for the same reason as the RLS tests: MySQL has no GUCs, so the
 * container binding below is not half the mechanism any more — it is all of it.
 */
it('binds the tenant so BelongsToTenant models are scoped inside a job', function () {
    FixtureTenantJob::$observedContainerTenant = null;

    $job = (new FixtureTenantJob())->withTenant('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
    $job->handle();

    expect(FixtureTenantJob::$observedContainerTenant)->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
});
