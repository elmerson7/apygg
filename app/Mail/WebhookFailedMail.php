<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WebhookFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $webhookName,
        public readonly string $event,
        public readonly string $endpoint,
        public readonly string $error,
        public readonly int $attempts,
        public readonly string $failedAt
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '⚠️ Webhook falló - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.webhook-failed',
            with: [
                'webhookName' => $this->webhookName,
                'event' => $this->event,
                'endpoint' => $this->endpoint,
                'error' => $this->error,
                'attempts' => $this->attempts,
                'failedAt' => $this->failedAt,
            ],
        );
    }
}