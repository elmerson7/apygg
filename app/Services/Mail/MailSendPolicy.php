<?php

namespace App\Services\Mail;

use App\Contracts\MailSendPolicyInterface;
use App\Contracts\MailSuppressionServiceInterface;
use App\Models\Mail\MailLog;
use App\Models\Mail\MailSetting;
use App\Models\Mail\MailTemplateConfig;

/**
 * MailSendPolicy
 *
 * Reglas que pueden frenar un envío: pausa global, plantilla pausada y límite
 * diario. Se evalúan en el momento de enviar (listener de `MessageSending`),
 * no al encolar: un correo que lleva una hora en cola respeta la pausa que se
 * activó mientras esperaba.
 */
class MailSendPolicy implements MailSendPolicyInterface
{
    public function __construct(private readonly MailSuppressionServiceInterface $suppressions) {}

    /**
     * Motivo por el que NO se puede enviar, o null si se puede.
     */
    public function denialReason(?string $templateKey): ?string
    {
        if (MailSetting::isGloballyPaused()) {
            return 'Pausa global activa';
        }

        if ($templateKey && MailTemplateConfig::isPaused($templateKey)) {
            return "Plantilla pausada ({$templateKey})";
        }

        $dailyLimit = (int) MailSetting::getValue(MailSetting::KEY_DAILY_LIMIT, 0);

        if ($dailyLimit > 0 && MailLog::sentToday() >= $dailyLimit) {
            return "Límite diario alcanzado ({$dailyLimit})";
        }

        return null;
    }

    public function deniedRecipients(array $addresses, ?string $templateKey): array
    {
        $denied = [];
        $suppressed = config('mail-system.suppression.enabled', true) ? $this->suppressions->lookup($addresses) : [];
        $critical = $templateKey !== null && in_array($templateKey, (array) config('mail-system.suppression.critical_templates', []), true);
        $ignored = (array) config('mail-system.suppression.critical_ignore_reasons', []);

        foreach ($addresses as $address) {
            $email = $this->suppressions->normalize($address);

            if ($domain = $this->reservedDomain($email)) {
                $denied[$address] = "Dominio reservado ({$domain})";

                continue;
            }

            $row = $suppressed[$email] ?? null;

            if ($row !== null && ! ($critical && in_array($row->reason, $ignored, true))) {
                $denied[$address] = "Destinatario suprimido ({$row->reason})";
            }
        }

        return $denied;
    }

    /**
     * Dominio/TLD reservado que casa con la dirección (RFC 2606/6761), o null. Apagable con
     * `mail-system.block_reserved_domains`.
     */
    private function reservedDomain(string $email): ?string
    {
        if (! config('mail-system.block_reserved_domains', true)) {
            return null;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);

        foreach ((array) config('mail-system.reserved_domains', []) as $reserved) {
            $reserved = mb_strtolower((string) $reserved);

            if ($reserved === '') {
                continue;
            }

            // ".test" es un TLD (cualquier subdominio); "example.com" es ese dominio y sus subdominios.
            $bare = ltrim($reserved, '.');

            if ($domain === $bare || str_ends_with($domain, '.'.$bare)) {
                return $reserved;
            }
        }

        return null;
    }
}
