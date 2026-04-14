<?php

namespace App\Mail\Penalty;

use App\Mail\BaseLibraryEmail;

class PenaltyPaidEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        return '✅ Multa saldada — Biblioteca Clásica';
    }

    protected function emailView(): string
    {
        return 'emails.penalty.paid';
    }
}