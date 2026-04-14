<?php

namespace App\Mail\Penalty;

use App\Mail\BaseLibraryEmail;

class PenaltyGeneratedEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        $amount = isset($this->data['total_amount'])
            ? 'S/ ' . number_format($this->data['total_amount'], 2)
            : '';
        return "Multa generada {$amount} — Biblioteca Clásica";
    }

    protected function emailView(): string
    {
        return 'emails.penalty.generated';
    }
}