<?php

namespace App\Models\Mail;

use App\Models\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MailTemplateConfig Model
 *
 * Configuración por plantilla de correo: desde qué cuenta se envía, si está
 * activa y qué asunto usa. Permite pausar un tipo de correo concreto desde el
 * panel sin desplegar código — útil cuando una plantilla genera quejas o el
 * proveedor está limitando.
 *
 * `template_key` es la clave que usa el código al enviar
 * (`MailService::send(..., templateKey: 'welcome')`).
 */
class MailTemplateConfig extends Model
{
    protected $table = 'mail_template_configs';

    protected $fillable = [
        'template_key',
        'template_name',
        'mail_account_id',
        'is_active',
        'subject_template',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * ¿Está pausada esta plantilla?
     */
    public static function isPaused(string $templateKey): bool
    {
        $config = self::query()->where('template_key', $templateKey)->first();

        return $config !== null && ! $config->is_active;
    }

    /**
     * Plantillas base del boilerplate. Amplíala en tu proyecto.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getDefaults(): array
    {
        return [
            ['template_key' => 'welcome', 'template_name' => 'Bienvenida', 'is_active' => true],
            ['template_key' => 'password_reset', 'template_name' => 'Recuperar contraseña', 'is_active' => true],
            ['template_key' => 'verification', 'template_name' => 'Verificación de correo', 'is_active' => true],
            ['template_key' => 'api_key_created', 'template_name' => 'API Key creada', 'is_active' => true],
            ['template_key' => 'webhook_failed', 'template_name' => 'Fallo de webhook', 'is_active' => true],
        ];
    }

    /**
     * Crear las plantillas por defecto sin pisar las existentes.
     */
    public static function seedDefaults(): void
    {
        foreach (self::getDefaults() as $template) {
            self::updateOrCreate(
                ['template_key' => $template['template_key']],
                $template
            );
        }
    }
}
