<?php
// app/Policies/KhataPolicy.php

namespace App\Policies;

class KhataPolicy
{
    /**
     * PRD §7 role matrix for the khata. The role comes from the membership
     * SetTenantContext verified, read here through app('tenant.role') — never
     * trusted from the client. Mirrors CatalogPolicy's shape.
     *
     * Reads ("View customer khata") are deliberately not gated: every role,
     * salesman and accountant included, needs to read a khata.
     */

    /** "Create sales / returns": owner, admin, salesman — not accountant. */
    public function recordSale(): bool
    {
        return in_array(app('tenant.role'), ['owner', 'admin', 'salesman'], true);
    }

    /** "Edit/void sales": owner, admin only. A correction is a privileged act. */
    public function voidSale(): bool
    {
        return in_array(app('tenant.role'), ['owner', 'admin'], true);
    }

    /** "Record payments": all four roles (owner, admin, salesman, accountant). */
    public function recordPayment(): bool
    {
        return in_array(app('tenant.role'), ['owner', 'admin', 'salesman', 'accountant'], true);
    }
}
