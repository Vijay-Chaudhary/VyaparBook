<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // BelongsToTenant's global scope, RequireTenant and CatalogPolicy all read
        // the tenant through the container. Laravel resolves an unbound string key
        // by trying to construct it as a class, so app('tenant.id') outside a
        // request throws "Target class [tenant.id] does not exist" rather than
        // returning null. Binding null defaults makes the contract total: a
        // factory, seeder, console command or unit test sees a well-defined
        // "no tenant", and SetTenantContext overrides these per request.
        //
        // bind(), not instance(): the container checks instances with
        // isset($this->instances[$abstract]), and isset(null) is false — a null
        // instance falls through to construction and throws anyway.
        $this->app->bind('tenant.id', fn () => null);
        $this->app->bind('tenant.role', fn () => null);
        $this->app->bind('tenant.user_id', fn () => null);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
