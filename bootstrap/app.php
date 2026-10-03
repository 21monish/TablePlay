<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\AuthorizeBroadcastChannel;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\EnsureRole;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum', AuthorizeBroadcastChannel::class]])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'device' => AuthenticateDevice::class,
            'entitlement' => EnsurePlanFeature::class,
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
