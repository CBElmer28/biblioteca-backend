<?php

// =============================================================================
// config/services.php — Servicios externos del identity-service
// =============================================================================

return [

    // GLPI — Sistema de tickets de soporte
    'glpi' => [
        'url'        => env('GLPI_URL'),
        'app_token'  => env('GLPI_APP_TOKEN'),
        'user_token' => env('GLPI_USER_TOKEN'),
    ],

    // Configuración de correo (hereda de .env global)
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
];