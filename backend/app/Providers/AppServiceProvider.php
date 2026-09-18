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

        // Fase 19.3: el límite global "api" (60/min) alcanzaba para un
        // heartbeat de ~1 req/25s, pero F19.4+ va a mandar posición desde
        // el frontend con un throttle de ~300ms (~200 req/min por
        // jugador activo) -60/min le quedaría corto de entrada-. 240/min
        // (~4/s) deja ~20% de margen sobre esos ~200/min sin abrir la
        // puerta a un spam real del endpoint. `->by($request->user()?->id)`
        // -nunca IP como bucket principal- porque el objetivo explícito es
        // que un jugador nunca consuma el presupuesto de otro; la ruta ya
        // exige auth:sanctum (mismo orden ya probado con el throttle:20,1
        // del chat: el auth:sanctum del grupo exterior corre antes que
        // este throttle), así que `?->id` siempre resuelve en la práctica
        // -el `?: $request->ip()` es solo la misma red de seguridad
        // defensiva que ya usa el limiter "api" arriba, nunca el caso real-.
        RateLimiter::for('presence.position', function (Request $request) {
            return Limit::perMinute(240)->by($request->user()?->id ?: $request->ip());
        });
    }
}
