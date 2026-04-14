<?php

namespace App\Mail\Loan;

use App\Mail\BaseLibraryEmail;

class LoanLostEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        return '📋 Aviso de pérdida de ejemplar — Biblioteca Clásica';
    }

    protected function emailView(): string
    {
        return 'emails.loan.lost';
    }
}