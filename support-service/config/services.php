<?php

return [
    // Configuración de correo (hereda de .env global)
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
];