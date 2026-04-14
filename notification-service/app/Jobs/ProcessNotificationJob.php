<?php

namespace App\Jobs;

// =============================================================================
// ProcessNotificationJob — Resuelve y envía el Mailable correcto.
// Mapeo completo de eventos del sistema bibliotecario → Mailable.
//
// Eventos del sistema:
//   identity-service : user.registered
//   loan-service     : loan.created | loan.renewed | loan.returned
//                      loan.overdue | loan.due_soon | loan.lost
//                      reservation.available
//   penalty-service  : penalty.generated | penalty.paid | penalty.waived
// =============================================================================

use App\Mail\Auth\WelcomeEmail;
use App\Mail\Loan\LoanCreatedEmail;
use App\Mail\Loan\LoanDueSoonEmail;
use App\Mail\Loan\LoanLostEmail;
use App\Mail\Loan\LoanOverdueEmail;
use App\Mail\Loan\LoanRenewedEmail;
use App\Mail\Loan\LoanReturnedEmail;
use App\Mail\Loan\ReservationAvailableEmail;
use App\Mail\Penalty\PenaltyGeneratedEmail;
use App\Mail\Penalty\PenaltyPaidEmail;
use App\Mail\Penalty\PenaltyWaivedEmail;
use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public array $backoff  = [30, 120, 300];
    public int   $timeout  = 60;

    public function __construct(
        private readonly string $eventType,
        private readonly array  $payload,
        private readonly string $sourceService
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $email = $this->resolveRecipientEmail();

        $log = NotificationLog::create([
            'event_type'         => $this->eventType,
            'source_service'     => $this->sourceService,
            'recipient_email'    => $email ?? 'unknown',
            'recipient_user_id'  => $this->payload['user_id'] ?? null,
            'notification_class' => $this->resolveClassName(),
            'status'             => 'pending',
            'event_payload'      => $this->payload,
        ]);

        try {
            $result = $this->dispatch($email);

            $log->update([
                'status'  => $result,
                'sent_at' => $result === 'sent' ? now() : null,
            ]);

        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'attempt'       => $this->attempts(),
            ]);

            Log::error("ProcessNotificationJob falló [{$this->eventType}]", [
                'error'   => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    // ── Resolver y enviar el Mailable ─────────────────────────────────────────

    private function dispatch(?string $email): string
    {
        if (!$email) {
            Log::warning("ProcessNotificationJob: sin email para [{$this->eventType}]");
            return 'skipped';
        }

        $mailable = $this->resolveMailable();

        if ($mailable === null) {
            return 'skipped';
        }

        Mail::to($email)->send($mailable);
        return 'sent';
    }

    private function resolveMailable(): ?Mailable
    {
        return match($this->eventType) {

            // ── Auth ──────────────────────────────────────────────────────────
            'user.registered'        => new WelcomeEmail($this->payload),

            // ── Préstamos ─────────────────────────────────────────────────────
            'loan.created'           => new LoanCreatedEmail($this->payload),
            'loan.renewed'           => new LoanRenewedEmail($this->payload),
            'loan.returned'          => new LoanReturnedEmail($this->payload),
            'loan.overdue'           => new LoanOverdueEmail($this->payload),
            'loan.due_soon'          => new LoanDueSoonEmail($this->payload),
            'loan.lost'              => new LoanLostEmail($this->payload),
            'reservation.available'  => new ReservationAvailableEmail($this->payload),

            // ── Multas ────────────────────────────────────────────────────────
            'penalty.generated'      => new PenaltyGeneratedEmail($this->payload),
            'penalty.paid'           => new PenaltyPaidEmail($this->payload),
            'penalty.waived'         => new PenaltyWaivedEmail($this->payload),

            // Eventos sin notificación de email
            default                  => null,
        };
    }

    private function resolveRecipientEmail(): ?string
    {
        return $this->payload['user_email']
            ?? $this->payload['email']
            ?? null;
    }

    private function resolveClassName(): string
    {
        return match($this->eventType) {
            'user.registered'       => 'WelcomeEmail',
            'loan.created'          => 'LoanCreatedEmail',
            'loan.renewed'          => 'LoanRenewedEmail',
            'loan.returned'         => 'LoanReturnedEmail',
            'loan.overdue'          => 'LoanOverdueEmail',
            'loan.due_soon'         => 'LoanDueSoonEmail',
            'loan.lost'             => 'LoanLostEmail',
            'reservation.available' => 'ReservationAvailableEmail',
            'penalty.generated'     => 'PenaltyGeneratedEmail',
            'penalty.paid'          => 'PenaltyPaidEmail',
            'penalty.waived'        => 'PenaltyWaivedEmail',
            default                 => 'UnknownNotification',
        };
    }

    public function failed(\Throwable $e): void
    {
        Log::critical("ProcessNotificationJob agotó reintentos [{$this->eventType}]", [
            'error'   => $e->getMessage(),
            'payload' => $this->payload,
        ]);
    }
}