<?php

// =============================================================================
// config/services.php — Servicios externos del identity-service
// =============================================================================

return [
    // Configuración de correo (hereda de .env global)
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
];