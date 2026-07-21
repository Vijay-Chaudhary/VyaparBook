<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\ThrottleRequests::class,
            prepend: \App\Http\Middleware\SetTenantContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
