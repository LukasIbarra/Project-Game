<?php

// Fase Deploy, sección 8: nunca `allowed_origins = ['*']` -acá se admite
// una lista separada por comas en FRONTEND_URL (ej.
// "https://juego.vercel.app,https://juego-git-preview.vercel.app") para
// poder sumar el dominio de producción y previews de Vercel sin volver a
// tocar código, pero siempre orígenes explícitos, nunca un comodín.
return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', env('FRONTEND_URL', 'http://localhost:4321')))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // El frontend nunca manda cookies (auth es 100% Bearer token vía
    // Sanctum personal access tokens, ver ApiClient.ts) — esto queda en
    // `true` porque es el default de Laravel y no representa un riesgo
    // real (allowed_origins nunca es un comodín), pero es config sin
    // efecto práctico en esta arquitectura.
    'supports_credentials' => true,

];