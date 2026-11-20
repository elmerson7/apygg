<?php

namespace App\Services\Mail;

use App\Contracts\MailSuppressionServiceInterface;
use App\Models\Mail\MailSuppression;
use Illuminate\Support\Facades\DB;

/**
 * MailSuppressionService
 *
 * Alta/baja y consulta de la lista de supresión. Reglas de entrada desde el proveedor (Resend):
 * `email.complained` suprime siempre; `email.bounced` solo si el rebote es permanente (los blandos
 * -buzón lleno, fallo temporal- se quedan en el historial y se reintentan en el siguiente envío).
 */
class MailSuppressionService implements MailSuppressionServiceInterface
{
    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function suppress(string $email, string $reason, string $source, array $details = []): MailSuppression
    {
        $email = $this->normalize($email);

        return DB::transaction(function () use ($email, $reason, $source, $details): MailSuppression {
            // El duplicado lo decide la restricción única (dos eventos a la vez no chocan).
            DB::table('mail_suppressions')->insertOrIgnore([
                'email' => $email,
                'reason' => $reason,
                'source' => $source,
                'details' => $details === [] ? null : json_encode($details),
                'suppressed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Una queja es más grave que un rebote; lo puesto a mano nunca se degrada.
            if ($reason === MailSuppression::REASON_COMPLAINT) {
                MailSuppression::query()
                    ->where('email', $email)
                    ->where('reason', MailSuppression::REASON_BOUNCE)
                    ->update([
                        'reason' => $reason,
                        'source' => $source,
                        'details' => $details === [] ? null : json_encode($details),
                        'updated_at' => now(),
                    ]);
            }

            return MailSuppression::query()->where('email', $email)->firstOrFail();
        });
    }

    public function suppressFromProviderEvent(array $payload, string $source): array
    {
        $type = (string) ($payload['type'] ?? '');

        if ($type === 'email.complained') {
            $reason = MailSuppression::REASON_COMPLAINT;
        } elseif ($type === 'email.bounced' && mb_strtolower((string) data_get($payload, 'data.bounce.type', '')) === 'permanent') {
            $reason = MailSuppression::REASON_BOUNCE;
        } else {
            return [];
        }

        $recipients = data_get($payload, 'data.to', []);
        $recipients = is_array($recipients) ? $recipients : [$recipients];

        $details = array_filter([
            'event' => $type,
            'bounce_type' => data_get($payload, 'data.bounce.type'),
            'bounce_sub_type' => data_get($payload, 'data.bounce.subType'),
            'provider_message_id' => data_get($payload, 'data.email_id'),
        ], fn ($v) => $v !== null && $v !== '');

        $suppressed = [];

        foreach ($recipients as $recipient) {
            if (! is_string($recipient) || ! str_contains($recipient, '@')) {
                continue;
            }

            // "Nombre <a@b.com>" también llega: se queda con la dirección.
            if (preg_match('/<([^>]+)>/', $recipient, $m)) {
                $recipient = $m[1];
            }

            $suppressed[] = $this->suppress($recipient, $reason, $source, $details)->email;
        }

        return $suppressed;
    }

    public function release(string $email): bool
    {
        return MailSuppression::query()->where('email', $this->normalize($email))->delete() > 0;
    }

    public function lookup(array $emails): array
    {
        $normalized = array_values(array_unique(array_map($this->normalize(...), $emails)));

        if ($normalized === []) {
            return [];
        }

        return MailSuppression::query()->whereIn('email', $normalized)->get()->keyBy('email')->all();
    }
}
