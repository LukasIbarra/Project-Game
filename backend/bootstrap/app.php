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
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Fase Deploy: Laravel 11 (a diferencia de 10) NO aplica
        // `throttle:api` a las rutas de `api.php` por defecto -queda en
        // el desarrollador agregarlo-. Sin esto, NINGÚN endpoint (login,
        // register, arena/attack, crafting, etc.) tenía límite de
        // requests, algo inaceptable para una demo pública. Usa el
        // limiter "api" que Laravel ya trae incorporado (60 req/min por
        // usuario autenticado o IP), sin inventar un sistema propio.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
