<?php

use App\Services\LoanService;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Models\Loan;
use App\Models\LoanReservation;

// ── Marcar préstamos vencidos
Schedule::call(function () {
    $count = app(LoanService::class)->markExpiredLoans();
    if ($count > 0) {
        Log::info("Scheduler: {$count} préstamo(s) marcados como vencidos.");
    }
})->name('mark-overdue-loans') // El name siempre PRIMERO
  ->description('Marca prestamos como expirados')
  ->hourly()
  ->withoutOverlapping();

// ── Alertas de vencimiento próximo
Schedule::call(function () {
    $alertDays = config('library.loans.alert_days_before_due', 1);
    $loans = Loan::dueWithinDays($alertDays)
        ->where('due_at', '>=', now()->addDay()->startOfDay())
        ->get();

    foreach ($loans as $loan) {
        Redis::publish('libreria.events', json_encode([
            'event'     => 'loan.due_soon',
            'payload'   => [
                'loan_id'    => $loan->id,
                'user_email' => $loan->user_email,
                'user_name'  => $loan->user_name,
                'book_title' => $loan->book_title,
                'due_at'     => $loan->due_at->toIso8601String(),
                'copy_code'  => $loan->copy_code,
                'is_digital' => $loan->is_digital,
            ],
            'timestamp' => now()->toIso8601String(),
            'source'    => 'loan-service',
        ]));
    }
})->name('loan-due-alerts')
  ->description('Envia alertas de vencimiento')
  ->dailyAt('08:00')
  ->withoutOverlapping();

// ── Expirar reservas no retiradas
Schedule::call(function () {
    LoanReservation::where('status', 'notified')
        ->where('expires_at', '<', now())
        ->update(['status' => 'expired']);
})->name('expire-reservations')
  ->description('Expira reservas de libros')
  ->everyThirtyMinutes()
  ->withoutOverlapping();