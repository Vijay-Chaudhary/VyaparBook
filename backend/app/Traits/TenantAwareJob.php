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

            // Bind the app-level tenant too, not just the Postgres GUC. Models
            // using BelongsToTenant read app('tenant.id') for their global scope;
            // with only the GUC set, a job would run with the DB layer scoped and
            // the app layer blind — losing the defense in depth both layers exist
            // to provide. No model used BelongsToTenant before the catalog, which
            // is why this never surfaced.
            app()->bind('tenant.id', fn () => $this->tenantId);

            $this->handleForTenant();
        });
    }

    abstract public function handleForTenant(): void;
}
