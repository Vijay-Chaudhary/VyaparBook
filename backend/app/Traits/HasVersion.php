<?php
// app/Traits/HasVersion.php

namespace App\Traits;

/**
 * Bumps an integer `version` column on every update.
 *
 * Kept separate from BelongsToTenant deliberately: versioning and tenant
 * scoping are unrelated concerns, and a future non-tenant model may want
 * versioning without a global scope.
 */
trait HasVersion
{
    public static function bootHasVersion(): void
    {
        // Stamp the app-level default so the in-memory instance agrees with the
        // migration's default(1). Without this, a model created and then updated
        // through the same instance (never reloaded from the DB) would enter
        // updating() with a null version — (int) null + 1 = 1 — silently failing
        // to bump. Controllers reload via findOrFail so they never hit it, but the
        // trait must be correct on its own, not by accident of its callers.
        static::creating(function ($model) {
            if ($model->version === null) {
                $model->version = 1;
            }
        });

        static::updating(function ($model) {
            $model->version = (int) $model->version + 1;
        });
    }
}
