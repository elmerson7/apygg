<?php

namespace App\Models\Mail;

use App\Models\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MailAccount Model
 *
 * Dirección concreta desde la que se envía correo (`from_email` + `from_name`)
 * asociada a un proveedor. Permite tener varias identidades de envío
 * (transaccional, notificaciones, soporte) y repartir plantillas entre ellas.
 *
 * Resolución de la cuenta a usar, en orden:
 *   1. La configurada para la plantilla (`MailTemplateConfig`)
 *   2. La marcada como `is_default`
 *   3. La activa de menor `priority`
 */
class MailAccount extends Model
{
    protected $table = 'mail_accounts';

    protected $fillable = [
        'name',
        'mail_provider_id',
        'from_email',
        'from_name',
        'is_active',
        'is_default',
        'priority',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'priority' => 'integer',
    ];

    public function mailProvider(): BelongsTo
    {
        return $this->belongsTo(MailProvider::class, 'mail_provider_id');
    }

    public function templateConfigs(): HasMany
    {
        return $this->hasMany(MailTemplateConfig::class, 'mail_account_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MailLog::class, 'mail_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Cuenta a usar para enviar, opcionalmente según la plantilla.
     */
    public static function getActiveForSending(?string $templateKey = null): ?self
    {
        if ($templateKey) {
            $config = MailTemplateConfig::query()
                ->where('template_key', $templateKey)
                ->where('is_active', true)
                ->first();

            if ($config?->mail_account_id) {
                $account = self::active()->find($config->mail_account_id);

                if ($account) {
                    return $account;
                }
            }
        }

        return self::active()->default()->first()
            ?? self::active()->orderBy('priority')->first();
    }

    /**
     * Cuenta alternativa cuando la principal falla, excluyendo su proveedor.
     */
    public static function getFallbackForSending(?int $excludeProviderId = null): ?self
    {
        $query = self::active()->orderBy('priority');

        if ($excludeProviderId) {
            $query->where(function ($q) use ($excludeProviderId) {
                $q->where('mail_provider_id', '!=', $excludeProviderId)
                    ->orWhereNull('mail_provider_id');
            });
        }

        return $query->first();
    }

    /**
     * Driver del proveedor asociado ('resend', 'smtp', 'ses'…).
     */
    public function getDriver(): string
    {
        return $this->mailProvider?->driver ?? config('mail.default');
    }

    public function isResend(): bool
    {
        return $this->getDriver() === 'resend';
    }

    /**
     * Credenciales efectivas del proveedor.
     *
     * Las claves NO se guardan en la BD: se leen de la configuración de la app.
     * `mail_providers.config` solo lleva datos no sensibles (host, puerto) o el
     * nombre de la clave de config a usar.
     *
     * @return array<string, mixed>
     */
    public function getCredentials(): array
    {
        $driver = $this->getDriver();
        $config = $this->mailProvider?->config ?? [];

        return match ($driver) {
            'resend' => [
                'key' => (string) config('services.resend.key'),
            ],
            'smtp' => [
                'host' => $config['host'] ?? config('mail.mailers.smtp.host'),
                'port' => (int) ($config['port'] ?? config('mail.mailers.smtp.port', 587)),
                'username' => $config['username'] ?? config('mail.mailers.smtp.username'),
                'password' => config('mail.mailers.smtp.password'),
                'encryption' => $config['encryption'] ?? 'tls',
            ],
            default => [],
        };
    }
}
