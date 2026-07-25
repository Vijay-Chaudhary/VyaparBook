<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant.context' => \App\Http\Middleware\SetTenantContext::class,
            'require.tenant' => \App\Http\Middleware\RequireTenant::class,
            'plan.gate' => \App\Http\Middleware\EnforceActivePlan::class,
            'require.platform_admin' => \App\Http\Middleware\RequirePlatformAdmin::class,
            'platform_admin.web' => \App\Http\Middleware\EnsurePlatformAdmin::class,
        ]);

        // Laravel's priority list sorts ThrottleRequests ahead of unlisted
        // custom middleware, so without this the per-tenant limiter would run
        // BEFORE the tenant is resolved and silently key on the user instead —
        // giving a shop's six staff six separate budgets and defeating the
        // noisy-neighbour containment entirely. It fails invisibly (limits still
        // "work", just on the wrong bucket), so it is pinned here.
        // The WhatsApp webhook is called by Meta, which holds no session and no
        // CSRF token. Its authenticity comes from the X-Hub-Signature-256 HMAC
        // verified in the controller, not from the session — so CSRF is
        // exempted here rather than weakened anywhere else.
        $middleware->validateCsrfTokens(except: ['webhooks/whatsapp']);

        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\ThrottleRequests::class,
            prepend: \App\Http\Middleware\SetTenantContext::class,
        );
    })
    /*
     | The app's FIRST scheduler (Phase 4c — automated payment reminders).
     |
     | OPERATIONAL PREREQUISITE: this does nothing unless the box runs
     |   * `php artisan schedule:run` every minute from cron, and
     |   * a queue worker (`php artisan queue:work`), since sending is queued.
     | Without both, reminders are planned and never sent — safe, but silent.
     |
     | Planning runs early so an owner has the working day to cancel anything
     | before dispatch releases it. Dispatch runs often because a batch held by
     | quiet hours or a not-yet-reached send time just waits for the next tick;
     | the dispatcher re-checks every safety condition, so extra passes are
     | cheap. withoutOverlapping so a slow run cannot double-send.
     */
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('reminders:plan')->dailyAt('06:00')->withoutOverlapping();
        $schedule->command('reminders:dispatch')->everyFifteenMinutes()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
