<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fase Deploy: el skeleton mínimo de Laravel 11 no trae el
        // RouteServiceProvider (ni el limiter "api") que Laravel 10 sí
        // registraba por defecto -bootstrap/app.php solo activa
        // `throttleApi()`, pero ese middleware necesita que este limiter
        // exista con ese nombre exacto, si no revienta con "Rate limiter
        // [api] is not defined." en cada request-. 60/min por usuario
        // autenticado (o por IP si no hay sesión) es el mismo valor que
        // Laravel usaba por defecto, no un número inventado.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
