<?php

namespace App\Models\Mail;

use App\Models\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MailProvider Model
 *
 * Proveedor de envío de correo (Resend, SMTP, SES…). Define el driver y su
 * configuración; las credenciales sensibles se resuelven desde `config/services`
 * o `config/mail`, nunca se guardan en claro en la BD.
 *
 * Un proveedor agrupa varias cuentas (`MailAccount`), que son las direcciones
 * concretas desde las que se envía.
 */
class MailProvider extends Model
{
    protected $table = 'mail_providers';

    protected $fillable = [
        'name',
        'driver',
        'config',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Cuentas de correo que usan este proveedor.
     *
     * @return HasMany<MailAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(MailAccount::class, 'mail_provider_id');
    }

    /**
     * Proveedores activos, ordenados por prioridad.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Leer un valor de la configuración del proveedor.
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
