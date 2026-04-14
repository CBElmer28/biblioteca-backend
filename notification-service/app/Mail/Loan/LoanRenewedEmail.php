<?php

namespace App\Mail\Loan;

use App\Mail\BaseLibraryEmail;

class LoanRenewedEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        $book = $this->data['book_title'] ?? 'su libro';
        return "Préstamo renovado: \"{$book}\"";
    }

    protected function emailView(): string
    {
        return 'emails.loan.renewed';
    }
}