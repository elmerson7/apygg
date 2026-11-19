<?php

namespace App\Mail;

use App\Mail\Transport\AccountsTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * SimpleMail
 *
 * Mailable genérico para enviar una vista Blade con datos. Es lo que usa
 * `MailService` (y por tanto el "correo de prueba"). Para un correo propio de
 * la app, crea su Mailable o Notification; no hace falta pasar por aquí.
 *
 * Va en cola (`ShouldQueue`); `Mail::...->sendNow()` lo envía en el acto.
 */
class SimpleMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $emailData  Datos para la vista
     * @param  array<int, array{content: string, filename: string}>  $files  Adjuntos
     */
    public function __construct(
        protected string $emailView,
        protected array $emailData = [],
        protected string $emailSubject = '',
        protected ?string $templateKey = null,
        protected ?int $accountId = null,
        protected array $files = [],
    ) {
        $this->onQueue(config('mail-system.queue', 'default'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject ?: config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: $this->emailView,
            with: $this->emailData,
        );
    }

    /**
     * Clave de plantilla y cuenta forzada: cabeceras internas que el listener
     * del sistema de correo lee y el transport retira antes de enviar.
     */
    public function headers(): Headers
    {
        return new Headers(text: array_filter([
            AccountsTransport::HEADER_TEMPLATE => $this->templateKey,
            AccountsTransport::HEADER_ACCOUNT => $this->accountId ? (string) $this->accountId : null,
        ]));
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file) => Attachment::fromData(fn () => $file['content'], $file['filename']),
            $this->files,
        );
    }
}
