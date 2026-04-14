<?php

// =============================================================================
// config/penalties.php — Reglas de negocio del módulo de multas.
// Centraliza todas las constantes económicas del dominio.
// =============================================================================

return [

    // Tarifa diaria por retraso (en PEN)
    'daily_rate_overdue'        => (float) env('PENALTY_DAILY_RATE', 1.00),

    // Multiplicador aplicado sobre el costo de adquisición para pérdida
    // Ej: libro costó S/45 → multa = 45 × 2.0 = S/90
    'loss_multiplier'           => (float) env('PENALTY_LOSS_MULTIPLIER', 2.0),

    // Costo de reposición por defecto si el ejemplar no tiene precio registrado
    'default_replacement_cost'  => (float) env('PENALTY_DEFAULT_REPLACEMENT', 50.00),

    // Tarifa fija por daño según nivel de deterioro detectado
    'damage_rates' => [
        // condition_out → condition_in  : tarifa fija en PEN
        'good_to_worn'      => 5.00,   // Buen estado → Desgastado
        'good_to_damaged'   => 20.00,  // Buen estado → Dañado
        'worn_to_damaged'   => 15.00,  // Desgastado  → Dañado
        'new_to_worn'       => 8.00,   // Nuevo       → Desgastado
        'new_to_damaged'    => 25.00,  // Nuevo       → Dañado
    ],

    // Tipos de multa reconocidos por el sistema
    'types' => [
        'overdue'  => 'Retraso en devolución',
        'damage'   => 'Daño al ejemplar',
        'loss'     => 'Pérdida del ejemplar',
        'combined' => 'Retraso y daño combinados',
    ],
];