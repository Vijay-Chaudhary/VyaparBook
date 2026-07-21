<?php
// tests/Unit/TenantAwareJobTest.php

use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

class FixtureTenantJob implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    public static ?string $observedTenant = null;
    public static ?string $observedContainerTenant = null;

    public function handleForTenant(): void
    {
        self::$observedTenant = DB::selectOne("select current_setting('app.current_tenant', true) as t")->t;
        self::$observedContainerTenant = app('tenant.id');
    }
}

it('sets the tenant GUC before running the job body', function () {
    FixtureTenantJob::$observedTenant = null;

    $job = (new FixtureTenantJob())->withTenant('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
    $job->handle();

    expect(FixtureTenantJob::$observedTenant)->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
});

it('binds the app-level tenant so BelongsToTenant models are scoped inside a job', function () {
    FixtureTenantJob::$observedContainerTenant = null;

    $job = (new FixtureTenantJob())->withTenant('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
    $job->handle();

    expect(FixtureTenantJob::$observedContainerTenant)->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
});
