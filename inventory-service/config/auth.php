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
        // Cambiamos 'eloquent' por 'database' para que deje de buscar la clase User
        'users' => [
            'driver' => 'database',
            'table'  => 'users', // No importa que no exista esta tabla aquí
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