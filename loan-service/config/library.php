<?php

// =============================================================================
// config/library.php — Reglas de negocio del sistema de préstamos.
// Centralizar aquí evita "magic numbers" dispersos en el código.
// =============================================================================

return [

    'loans' => [
        // Duración estándar de un préstamo físico en días
        'max_days'             => (int) env('LOAN_MAX_DAYS', 14),

        // Duración de préstamo para e-books (sin multas por retraso)
        'ebook_max_days'       => (int) env('EBOOK_LOAN_MAX_DAYS', 30),

        // Número máximo de renovaciones por préstamo
        'max_renewals'         => (int) env('LOAN_MAX_RENEWALS', 2),

        // Días que agrega cada renovación a la fecha de vencimiento
        'renewal_days'         => (int) env('LOAN_RENEWAL_DAYS', 7),

        // Préstamos activos simultáneos por lector
        'max_active_per_user'  => (int) env('LOAN_MAX_ACTIVE_PER_USER', 3),

        // Días de anticipación para enviar alerta de vencimiento próximo
        'alert_days_before_due' => 1,
    ],

    'penalties' => [
        // Tarifa diaria por retraso en devolución (en PEN)
        'daily_rate_overdue'   => (float) env('PENALTY_DAILY_RATE', 1.00),

        // Multiplicador del costo de adquisición para multa por pérdida
        'loss_multiplier'      => (float) env('PENALTY_LOSS_MULTIPLIER', 2.0),

        // Costo de reposición por defecto si el ejemplar no tiene precio registrado
        'default_replacement'  => (float) env('PENALTY_DEFAULT_REPLACEMENT', 50.00),
    ],

];