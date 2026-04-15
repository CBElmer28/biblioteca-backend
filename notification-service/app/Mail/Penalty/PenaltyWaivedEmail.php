<?php

namespace App\Mail\Penalty;

use App\Mail\BaseLibraryEmail;

class PenaltyWaivedEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        return 'Multa condonada — Biblioteca Clásica';
    }

    protected function emailView(): string
    {
        return 'emails.penalty.waived';
    }
}