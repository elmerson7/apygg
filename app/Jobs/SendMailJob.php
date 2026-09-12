<?php

namespace App\Jobs;

use App\Mail\SimpleMail;
use App\Models\Mail\MailAccount;
use App\Models\Mail\MailLog;
use App\Models\Mail\MailTemplateConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SendMailJob
 *
 * Envía un correo a través de la cuenta indicada y mantiene actualizado su
 * registro en `mail_logs`.
 *
 * Antes de enviar comprueba que la plantilla no esté pausada: permite cortar un
 * tipo de correo desde el panel sin desplegar, incluso con la cola ya llena.
 *
 * Se despacha desde `MailService`, no directamente.
 */
class SendMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $viewData
     * @param  array<int, array{content: string, filename: string}>  $attachments
     */
    public function __construct(
        protected string $toEmail,
        protected ?string $toName,
        protected string $subject,
        protected string $view,
        protected array $viewData = [],
        protected ?string $templateKey = null,
        protected ?int $mailAccountId = null,
        protected ?int $mailLogId = null,
        protected array $attachments = [],
    ) {
        $this->onQueue(config('mail-system.queue', 'default'));
    }

    public function handle(): void
    {
        if ($this->templateKey && MailTemplateConfig::isPaused($this->templateKey)) {
            Log::info('SendMailJob: plantilla pausada, se omite el envío', [
                'to' => $this->toEmail,
                'template' => $this->templateKey,
            ]);

            $this->updateLogStatus(MailLog::STATUS_PENDING, 'Plantilla pausada');

            return;
        }

        $account = $this->resolveAccount();

        if (! $account) {
            $this->updateLogStatus(MailLog::STATUS_FAILED, 'No hay cuenta de correo disponible');
            $this->fail(new \RuntimeException('No hay cuenta de correo disponible'));

            return;
        }

        if ($account->isResend()) {
            $this->sendViaResend($account);

            return;
        }

        $this->sendViaMailer($account);
    }

    protected function resolveAccount(): ?MailAccount
    {
        if ($this->mailAccountId) {
            return MailAccount::find($this->mailAccountId);
        }

        return MailAccount::getActiveForSending($this->templateKey);
    }

    /**
     * Envío por la API HTTP de Resend.
     *
     * Se usa la API directa en lugar del mailer de Laravel para obtener el
     * `message_id`, que es lo que permite correlacionar después los webhooks
     * de entrega y rebote con este `MailLog`.
     */
    protected function sendViaResend(MailAccount $account): void
    {
        $apiKey = $account->getCredentials()['key'] ?? null;

        if (! $apiKey) {
            $this->updateLogStatus(MailLog::STATUS_FAILED, 'Resend sin API key configurada');
            $this->fail(new \RuntimeException('Resend sin API key configurada'));

            return;
        }

        try {
            $payload = [
                'from' => sprintf('%s <%s>', $account->from_name, $account->from_email),
                'to' => $this->toName
                    ? sprintf('%s <%s>', $this->toName, $this->toEmail)
                    : $this->toEmail,
                'subject' => $this->subject,
                'html' => $this->renderView(),
            ];

            if (! empty($this->attachments)) {
                $payload['attachments'] = array_map(fn (array $a) => [
                    'content' => $a['content'],
                    'filename' => $a['filename'],
                ], $this->attachments);
            }

            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.resend.com/emails', $payload);

            if ($response->successful()) {
                $messageId = $response->json('id');

                $this->updateLogStatus(MailLog::STATUS_SENT, null, $messageId);

                Log::info('SendMailJob: correo enviado vía Resend', [
                    'to' => $this->toEmail,
                    'message_id' => $messageId,
                ]);

                return;
            }

            $status = $response->status();
            $body = $response->body();
            $message = $response->json('message') ?? $body;

            $this->updateLogStatus(MailLog::STATUS_FAILED, $message);

            if ($this->isQuotaError($status, (string) $message)) {
                // Reintentar no sirve de nada: la cuota no se recupera en 30s.
                Log::warning('SendMailJob: cuota de Resend agotada', [
                    'to' => $this->toEmail,
                    'status' => $status,
                    'error' => $message,
                ]);

                $this->fail(new \RuntimeException('Cuota de Resend agotada: '.$message));

                return;
            }

            Log::error('SendMailJob: la API de Resend devolvió error', [
                'to' => $this->toEmail,
                'status' => $status,
                'error' => $body,
            ]);

            throw new \RuntimeException('Resend devolvió error: '.$body);
        } catch (\Throwable $e) {
            $this->updateLogStatus(MailLog::STATUS_FAILED, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Envío por el mailer de Laravel (SMTP, SES, log…).
     */
    protected function sendViaMailer(MailAccount $account): void
    {
        try {
            $mailable = new SimpleMail($this->view, $this->viewData, $this->subject);
            $mailable->from($account->from_email, $account->from_name);

            // Mailer::to() recibe un único destinatario; el nombre se
            // pasa como array, no como segundo argumento.
            Mail::mailer($account->getDriver())
                ->to([['email' => $this->toEmail, 'name' => $this->toName]])
                ->send($mailable);

            $this->updateLogStatus(MailLog::STATUS_SENT, null);

            Log::info('SendMailJob: correo enviado', [
                'driver' => $account->getDriver(),
                'account' => $account->name,
                'to' => $this->toEmail,
                'subject' => $this->subject,
            ]);
        } catch (\Throwable $e) {
            $this->updateLogStatus(MailLog::STATUS_FAILED, $e->getMessage());

            Log::error('SendMailJob: fallo de envío', [
                'driver' => $account->getDriver(),
                'to' => $this->toEmail,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Un 429/403 o un mensaje de cuota no se arregla reintentando.
     */
    protected function isQuotaError(int $statusCode, string $message): bool
    {
        if (in_array($statusCode, [403, 429], true)) {
            return true;
        }

        foreach (['credit', 'quota', 'limit', 'exhausted', 'insufficient'] as $keyword) {
            if (stripos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function renderView(): string
    {
        try {
            return view($this->view, $this->viewData)->render();
        } catch (\Throwable $e) {
            Log::error('SendMailJob: error al renderizar la vista del correo', [
                'view' => $this->view,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    protected function updateLogStatus(string $status, ?string $errorMessage, ?string $messageId = null): void
    {
        if (! $this->mailLogId) {
            return;
        }

        $data = [
            'status' => $status,
            'error_message' => $errorMessage,
        ];

        if ($status === MailLog::STATUS_SENT) {
            $data['sent_at'] = now();
        }

        if ($messageId) {
            $data['provider_message_id'] = $messageId;
        }

        MailLog::where('id', $this->mailLogId)->update($data);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendMailJob: fallo definitivo', [
            'to' => $this->toEmail,
            'template' => $this->templateKey,
            'error' => $exception->getMessage(),
        ]);

        $this->updateLogStatus(MailLog::STATUS_FAILED, $exception->getMessage());
    }
}
