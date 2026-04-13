<?php

// =============================================================================
// config/auth.php — Configuración de autenticación del identity-service
// Guard principal: JWT (stateless, ideal para microservicios)
// =============================================================================

return [

    'defaults' => [
        'guard'     => 'api',   // JWT como guard por defecto (no sesiones)
        'passwords' => 'users',
    ],

    'guards' => [
        // Guard stateless JWT para la API REST
        'api' => [
            'driver'   => 'jwt',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => 'password_reset_tokens',
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];