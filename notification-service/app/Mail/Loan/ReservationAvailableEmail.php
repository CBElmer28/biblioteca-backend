<?php

namespace App\Mail\Loan;

use App\Mail\BaseLibraryEmail;

class ReservationAvailableEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        $book = $this->data['book_title'] ?? 'su libro reservado';
        return "📬 \"{$book}\" ya está disponible para retirar";
    }

    protected function emailView(): string
    {
        return 'emails.loan.reservation_available';
    }
}