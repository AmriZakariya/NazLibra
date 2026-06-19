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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.access'  => \App\Http\Middleware\EnsureTenantAccess::class,
            'session.unlocked' => \App\Http\Middleware\EnsureSessionUnlocked::class,
            'device.selected' => \App\Http\Middleware\EnsureVirtualDeviceSelected::class,
            'api.context'    => \App\Http\Middleware\ResolveApiContext::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\SetLibraireProLocale::class,
            \App\Http\Middleware\RecordUserActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
