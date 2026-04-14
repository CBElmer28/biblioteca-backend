<?php

namespace App\Mail\Auth;

use App\Mail\BaseLibraryEmail;

class WelcomeEmail extends BaseLibraryEmail
{
    protected function emailSubject(): string
    {
        return '¡Bienvenido/a a la Biblioteca Clásica! 📚';
    }

    protected function emailView(): string
    {
        return 'emails.auth.welcome';
    }
}