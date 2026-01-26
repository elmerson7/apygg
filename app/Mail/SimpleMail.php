<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * SimpleMail
 *
 * Mailable simple para enviar emails usando vistas.
 * Puede ser usado con queue automáticamente si se implementa ShouldQueue.
 */
class SimpleMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Vista del email
     */
    protected string $emailView;

    /**
     * Datos para la vista
     */
    protected array $emailData;

    /**
     * Asunto del email
     */
    protected string $emailSubject;

    /**
     * Create a new message instance.
     *
     * @param  string  $view  Vista del email
     * @param  array  $data  Datos para la vista
     * @param  string  $subject  Asunto del email
     */
    public function __construct(
        string $view,
        array $data = [],
        string $subject = ''
    ) {
        $this->emailView = $view;
        $this->emailData = $data;
        $this->emailSubject = $subject;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject ?: config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: $this->emailView,
            with: $this->emailData,
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
