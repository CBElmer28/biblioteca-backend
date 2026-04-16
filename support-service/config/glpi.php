<?php

// =============================================================================
// config/glpi.php — Configuración completa de la integración GLPI.
// Centraliza URLs, tokens, categorías y mapeos de prioridades.
// El GlpiService depende exclusivamente de este archivo — nunca de env() directo.
// =============================================================================

return [

    'url'       => rtrim(env('GLPI_URL', ''), '/'),
    'app_token' => env('GLPI_APP_TOKEN', ''),
    'user_token'=> env('GLPI_USER_TOKEN', ''),

    // TTL del session_token cacheado (Stripe recomienda < 60 min)
    'session_ttl_minutes' => (int) env('GLPI_SESSION_TTL_MINUTES', 50),

    // Usuario del sistema para tickets creados automáticamente por eventos
    'system_requester_email' => env('GLPI_DEFAULT_REQUESTER_EMAIL', 'sistema@biblioteca-clasica.pe'),

    // ── Mapeo de urgencias (1=muy bajo … 5=muy alto) ─────────────────────────
    'urgency' => [
        'low'      => 2,
        'normal'   => 3,
        'high'     => 4,
        'critical' => 5,
    ],

    // ── Mapeo de tipos de ticket ──────────────────────────────────────────────
    'ticket_type' => [
        'incident' => 1,
        'request'  => 2,
    ],

    // ── Mapeo de estados de ticket en GLPI ───────────────────────────────────
    'status' => [
        1 => 'new',
        2 => 'processing_assigned',
        3 => 'processing_planned',
        4 => 'pending',
        5 => 'solved',
        6 => 'closed',
    ],

    // ── Categorías de tickets del sistema bibliotecario ───────────────────────
    // IDs definidos en la instancia GLPI — ajustar según configuración real
    'categories' => [
        'book_damage'       => env('GLPI_CAT_BOOK_DAMAGE', null),
        'penalty_critical'  => env('GLPI_CAT_PENALTY_CRITICAL', null),
        'loan_dispute'      => env('GLPI_CAT_LOAN_DISPUTE', null),
        'user_account'      => env('GLPI_CAT_USER_ACCOUNT', null),
        'inventory'         => env('GLPI_CAT_INVENTORY', null),
        'general'           => env('GLPI_CAT_GENERAL', null),
    ],

];