<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApiKeyCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $key,
        public readonly string $createdAt,
        public readonly ?string $expiresAt = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nueva API Key creada - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.api-key-created',
            with: [
                'name' => $this->name,
                'key' => $this->key,
                'createdAt' => $this->createdAt,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}