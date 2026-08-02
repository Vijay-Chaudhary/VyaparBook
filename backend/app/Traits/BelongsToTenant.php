<?php
// app/Traits/BelongsToTenant.php

namespace App\Traits;

use App\Exceptions\TenantContextMissing;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scopes every query to the bound tenant, and REFUSES to run if there is none.
 *
 * This used to fail open — no tenant meant no predicate, so the query returned
 * every tenant's rows. That was safe only because Postgres RLS refused
 * underneath it. On MySQL there is nothing underneath, so the same code would
 * be a cross-tenant data leak; it now throws instead.
 *
 * Legitimately cross-tenant work goes through Tenancy::withoutTenant(), which
 * is greppable. Note this binds Eloquent only: a raw DB::table() walks straight
 * past it, which is what the query tripwire in QueryTripwireServiceProvider
 * exists to catch in tests.
 *
 * TWO ELOQUENT METHODS ALSO BYPASS IT, and neither is obvious: `$model->fresh()`
 * and `$model->refresh()` build their query with newQueryWithoutScopes(), so
 * they re-read a row with NO tenant predicate. Use `freshScoped()` below instead;
 * the tripwire flags the difference.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (Tenancy::isSuspended()) {
                return;
            }

            $tenantId = app('tenant.id');

            if ($tenantId === null) {
                throw TenantContextMissing::for($builder->getModel()::class);
            }

            $builder->where($builder->getModel()->getTable().'.business_id', $tenantId);
        });

        static::creating(function ($model) {
            if (empty($model->business_id)) {
                $model->business_id = app('tenant.id');
            }
        });
    }

    /**
     * Re-read this row from the database WITH the tenant scope applied.
     *
     * The replacement for fresh(), whose newQueryWithoutScopes() would drop the
     * tenant predicate. newQuery() keeps global scopes, so the reload is confined
     * to the bound tenant exactly as the original read was.
     *
     * Used after a write to return the row's real persisted state -- database
     * defaults and any column a trigger or the DB filled in are only knowable by
     * reading back.
     */
    public function freshScoped(): static
    {
        return $this->newQuery()->findOrFail($this->getKey());
    }
}
