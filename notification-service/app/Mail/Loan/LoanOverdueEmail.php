<?php

namespace App\Mail\Loan;

use App\Mail\BaseLibraryEmail;

class LoanOverdueEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        $book = $this->data['book_title'] ?? 'un libro';
        return "⚠ Préstamo vencido: \"{$book}\"";
    }

    protected function emailView(): string
    {
        return 'emails.loan.overdue';
    }
}