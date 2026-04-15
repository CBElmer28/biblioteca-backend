<?php

namespace App\Mail\Loan;

use App\Mail\BaseLibraryEmail;

class LoanCreatedEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        $book = $this->data['book_title'] ?? 'su libro';
        return "Préstamo registrado: {$book}";
    }

    protected function emailView(): string
    {
        return 'emails.loan.created';
    }
}