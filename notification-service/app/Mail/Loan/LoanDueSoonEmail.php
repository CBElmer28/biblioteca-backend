<?php

namespace App\Mail\Loan;

use App\Mail\BaseLibraryEmail;

class LoanDueSoonEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        $book = $this->data['book_title'] ?? 'su libro';
        return "⏰ Recordatorio: \"{$book}\" vence mañana";
    }

    protected function emailView(): string
    {
        return 'emails.loan.due_soon';
    }
}