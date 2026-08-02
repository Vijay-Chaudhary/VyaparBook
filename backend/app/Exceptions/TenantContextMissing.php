<?php
// app/Exceptions/TenantContextMissing.php

namespace App\Exceptions;

use RuntimeException;

/**
 * A tenant-owned model was queried with no tenant bound.
 *
 * Under Postgres this was harmless — RLS returned nothing regardless. On MySQL
 * an unscoped query returns EVERY tenant's rows, so this is a hard failure
 * rather than a warning. If the query is legitimately cross-tenant, wrap it in
 * Tenancy::withoutTenant() so the intent is explicit and greppable.
 */
class TenantContextMissing extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(
            "Refusing to query {$model} with no tenant bound: this would return every ".
            'tenant\'s rows. Wrap the call in Tenancy::withoutTenant() if it is '.
            'deliberately cross-tenant.'
        );
    }
}
