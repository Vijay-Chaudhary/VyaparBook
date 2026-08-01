<?php
// app/Traits/TenantAwareJob.php

namespace App\Traits;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

trait TenantAwareJob
{
    public string $tenantId;

    public function withTenant(string $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function handle(): void
    {
        DB::transaction(function () {
            TenantContext::switchTo($this->tenantId);

            // switchTo() binds tenant.id, which is the whole mechanism now.
            // Models using BelongsToTenant read app('tenant.id') for their
            // global scope, and a job that skipped this would not merely run
            // unscoped — the scope fails closed, so it would throw.
            app()->bind('tenant.id', fn () => $this->tenantId);

            $this->handleForTenant();
        });
    }

    abstract public function handleForTenant(): void;
}
