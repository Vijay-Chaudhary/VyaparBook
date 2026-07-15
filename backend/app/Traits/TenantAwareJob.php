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
            $this->handleForTenant();
        });
    }

    abstract public function handleForTenant(): void;
}
