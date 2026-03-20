<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $verificationUrl,
        public readonly string $expiresAt
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verifica tu correo electrónico - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verification',
            with: [
                'name' => $this->name,
                'verificationUrl' => $this->verificationUrl,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}