<?php
// app/Policies/BillingPolicy.php

namespace App\Policies;

class BillingPolicy
{
    /**
     * PRD §7, "Billing & plan": owner only — stricter than stock/production
     * (owner+admin). Admin, salesman and accountant have no billing access,
     * reads included. Role comes from the membership SetTenantContext verified,
     * never trusted from the client. Role-equality, not in_array, is deliberate.
     */
    public function manage(): bool
    {
        return app('tenant.role') === 'owner';
    }
}
