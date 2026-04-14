<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

abstract class BaseLibraryEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(protected readonly array $data) {}

    abstract protected function emailSubject(): string;
    abstract protected function emailView(): string;

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject());
    }

    public function content(): Content
    {
        return new Content(view: $this->emailView(), with: $this->data);
    }
}