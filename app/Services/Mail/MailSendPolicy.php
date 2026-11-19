<?php

namespace App\Services\Mail;

use App\Contracts\MailSendPolicyInterface;
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
}
