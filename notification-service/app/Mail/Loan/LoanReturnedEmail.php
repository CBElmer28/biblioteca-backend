<?php

namespace App\Mail\Loan;

use App\Mail\BaseLibraryEmail;

class LoanReturnedEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        $book = $this->data['book_title'] ?? 'su libro';
        return "Devolución registrada: \"{$book}\"";
    }

    protected function emailView(): string
    {
        return 'emails.loan.returned';
    }
}