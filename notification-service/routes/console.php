<?php

use Illuminate\Support\Facades\Schedule;

// Limpiar logs de notificaciones exitosas con más de 90 días
Schedule::command('model:prune', [
    '--model' => \App\Models\NotificationLog::class,
])->monthly()->withoutOverlapping();