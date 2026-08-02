<?php

namespace App\Providers;

use App\Reminders\CloudApiSender;
use App\Reminders\Contracts\WhatsAppSender;
use App\Reminders\LogSender;
use App\Reminders\WhatsAppConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Drop every database connection this project does not run on.
     *
     * config/database.php defines only mysql and mysql_platform, but Laravel 11
     * merges vendor/laravel/framework/config/database.php underneath it as a
     * base (LoadConfiguration, which array_merges nested keys), so the
     * framework's stock `pgsql`, `sqlsrv` and `mariadb` entries reappear in
     * config('database.connections') however the app file is written.
     *
     * They are inert -- nothing selects them -- but this project deliberately
     * runs on MySQL alone, and an inherited `pgsql` entry is exactly the kind of
     * thing a stray DB::connection('pgsql') would find and half-work against.
     * Removing them turns that into an immediate "Database connection [pgsql]
     * not configured" instead.
     */
    private function forgetNonMysqlConnections(): void
    {
        $keep = ['mysql', 'mysql_platform'];

        config([
            'database.connections' => array_intersect_key(
                config('database.connections', []),
                array_flip($keep),
            ),
        ]);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->forgetNonMysqlConnections();

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

        // Phase 4b: which WhatsApp transport reminders leave by. Defaults to
        // 'log', which sends nothing — the integration ships dark and only goes
        // live on a deliberate config change.
        //
        // An unknown driver throws rather than falling back to 'log': a typo in
        // WHATSAPP_DRIVER must not look exactly like a working install that
        // silently delivers nothing.
        $this->app->bind(WhatsAppSender::class, fn () => match (WhatsAppConfig::driver()) {
            'cloud_api' => new CloudApiSender,
            'log' => new LogSender,
            default => throw new InvalidArgumentException(
                'Unknown whatsapp driver ['.WhatsAppConfig::driver().']; expected "log" or "cloud_api".'
            ),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * Per-tenant rate limits (PRD §13, §14) — noisy-neighbour containment.
     *
     * Keyed per TENANT rather than per user or per IP: one busy shop must not
     * be able to spend every other shop's budget on the shared database, and a
     * shop's six staff on six phones are one unit of load, not six.
     *
     * These run INSIDE the tenant.context group, so app('tenant.id') is already
     * resolved when the key is built. Ordering matters — throttling before the
     * tenant is known would silently collapse every tenant into one bucket.
     */
    private function registerRateLimiters(): void
    {
        // Reads and writes get separate buckets so a read storm (someone
        // refreshing the khata list) cannot block the sale they are trying to
        // record. Writes are the scarcer resource, so the smaller allowance.
        RateLimiter::for('tenant', function (Request $request) {
            $key = $this->tenantKey($request);

            return $request->isMethodSafe()
                ? Limit::perMinute(config('ratelimits.tenant_read'))->by("t-read:{$key}")
                : Limit::perMinute(config('ratelimits.tenant_write'))->by("t-write:{$key}");
        });

        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(config('ratelimits.sync'))
            ->by('sync:'.$this->tenantKey($request)));

        RateLimiter::for('platform', fn (Request $request) => Limit::perMinute(config('ratelimits.platform'))
            ->by('platform:'.(auth()->id() ?? $request->ip())));

        // No tenant exists yet at login, so this is the one limit keyed per
        // credential: per email AND IP, so one attacker cannot lock out a real
        // user by hammering their address from elsewhere.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('ratelimits.login'))
            ->by('login:'.Str::lower((string) $request->input('email')).'|'.$request->ip()));
    }

    /**
     * The bucket a request belongs to: its tenant when one is resolved, else
     * the user, else the IP. Falling back rather than exempting means a
     * tenant-less authenticated route is still bounded.
     */
    private function tenantKey(Request $request): string
    {
        return (string) (app('tenant.id') ?? auth()->id() ?? $request->ip());
    }
}
