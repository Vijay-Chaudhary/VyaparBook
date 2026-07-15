<?php
// app/Traits/BelongsToTenant.php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = app('tenant.id');
            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable() . '.business_id', $tenantId);
            }
        });

        static::creating(function ($model) {
            if (empty($model->business_id)) {
                $model->business_id = app('tenant.id');
            }
        });
    }
}
