<?php
// app/Policies/CatalogPolicy.php

namespace App\Policies;

class CatalogPolicy
{
    /**
     * PRD §7, "Manage catalog & prices": owner and admin only.
     *
     * Reads are deliberately not gated — a salesman cannot sell without the
     * catalog and an accountant needs it to read a khata. The role comes from
     * the membership SetTenantContext verified, never from the client.
     */
    public function manage(): bool
    {
        return in_array(app('tenant.role'), ['owner', 'admin'], true);
    }
}
