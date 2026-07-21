<?php
// app/Policies/StockPolicy.php

namespace App\Policies;

class StockPolicy
{
    /**
     * PRD §7, "Stock & production": owner and admin only.
     *
     * Unlike catalog and khata, reads are gated too — salesman and accountant
     * have no stock/production access at all, so EVERY stock and production
     * endpoint (reads included) calls this. The role comes from the membership
     * SetTenantContext verified, never trusted from the client.
     */
    public function manage(): bool
    {
        return in_array(app('tenant.role'), ['owner', 'admin'], true);
    }
}
