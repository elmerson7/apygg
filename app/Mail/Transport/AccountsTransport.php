<?php

namespace App\Mail\Transport;

use App\Models\Mail\MailAccount;
use App\Models\Mail\MailLog;
use Closure;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * AccountsTransport
 *
 * Transport de Laravel (`MAIL_DEFAULT_MAILER=accounts`) que decide en cada
 * envío por qué proveedor sale el correo, según la cuenta activa en la BD.
 *
 * No guarda estado: la cuenta llega en la cabecera interna `X-Mail-Account`
 * (la deja el listener de `MessageSending`) y se resuelve el transport real
 * del proveedor en ese momento. Por eso es seguro con Octane y con workers de
 * larga vida: cambiar la cuenta activa en el panel surte efecto en el
 * siguiente envío sin reiniciar nada.
 *
 * Las cabeceras internas (`X-Mail-*`) se quitan antes de entregar el mensaje
 * al proveedor.
 */
class AccountsTransport extends AbstractTransport
{
    public const HEADER_ACCOUNT = 'X-Mail-Account';

    public const HEADER_LOG = 'X-Mail-Log';

    public const HEADER_TEMPLATE = 'X-Mail-Template';

    /**
     * @param  Closure(): MailManager  $manager
     */
    public function __construct(private readonly Closure $manager)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Email) {
            throw new \LogicException('AccountsTransport solo envía mensajes Email.');
        }

        $headers = $original->getHeaders();
        $accountId = $headers->has(self::HEADER_ACCOUNT)
            ? (int) $headers->get(self::HEADER_ACCOUNT)?->getBodyAsString()
            : null;
        $logId = $headers->has(self::HEADER_LOG)
            ? (int) $headers->get(self::HEADER_LOG)?->getBodyAsString()
            : null;

        try {
            $account = $accountId ? MailAccount::query()->with('mailProvider')->find($accountId) : null;
            $driver = $account?->getDriver() ?? $this->fallbackDriver();

            // Copia sin cabeceras internas: lo que ve el proveedor.
            $outgoing = clone $original;

            foreach ([self::HEADER_ACCOUNT, self::HEADER_LOG, self::HEADER_TEMPLATE] as $name) {
                $outgoing->getHeaders()->remove($name);
            }

            $sent = ($this->manager)()->mailer($driver)->getSymfonyTransport()
                ->send($outgoing, $message->getEnvelope());

            if ($sent?->getMessageId()) {
                $message->setMessageId($sent->getMessageId());
            }
        } catch (\Throwable $e) {
            if ($logId) {
                MailLog::query()->whereKey($logId)->update([
                    'status' => MailLog::STATUS_FAILED,
                    'error_message' => mb_substr($e->getMessage(), 0, 1000),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Driver cuando no hay cuenta en la BD (instalación sin sembrar): el de
     * `MAIL_MAILER`, como en cualquier proyecto Laravel.
     */
    private function fallbackDriver(): string
    {
        $driver = (string) config('mail.fallback', 'log');

        return $driver === 'accounts' ? 'log' : $driver;
    }

    public function __toString(): string
    {
        return 'accounts://';
    }
}
