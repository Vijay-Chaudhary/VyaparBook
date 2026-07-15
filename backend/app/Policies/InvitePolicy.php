<?php

namespace App\Policies;

class InvitePolicy
{
    public function create(): bool
    {
        return in_array(app('tenant.role'), ['owner', 'admin'], true);
    }
}
