<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Logs\ActivityLog;
use App\Traits\HasApiTokens;
use App\Traits\LogsActivity;
use App\Traits\Searchable;
use App\Traits\SoftDeletesWithUser;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

/**
 * User Model
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $username
 * @property string $password
 * @property string|null $timezone
 * @property string|null $identity_document
 * @property string|null $provider
 * @property string|null $provider_id
 * @property array|null $preferences
 * @property int $token_version
 * @property string|null $deleted_by
 * @property Carbon $email_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, LogsActivity, Notifiable, Searchable, SoftDeletes, SoftDeletesWithUser;

    /**
     * Guard de Spatie: los roles y permisos de usuario viven en el guard de la API (JWT),
     * sea cual sea el guard por defecto del worker.
     *
     * @var string
     */
    protected $guard_name = 'api';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'username',
        'password',
        'timezone',
        'identity_document',
        'deleted_by',
        'provider',
        'provider_id',
        'preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'deleted_by' => 'integer',
        'preferences' => 'array',
    ];

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        // `tv`: versión de tokens; ver revokeAllTokens().
        return ['tv' => (int) ($this->token_version ?? 0)];
    }

    /**
     * Invalida todas las sesiones (access y refresh) emitidas hasta ahora.
     *
     * Incremento atómico en SQL: dos revocaciones concurrentes no se pisan.
     */
    public function revokeAllTokens(): void
    {
        $this->increment('token_version');
    }

    /**
     * Obtener el timezone del usuario o el timezone por defecto de la aplicación
     *
     * @return string Timezone del usuario o timezone por defecto
     */
    public function getTimezone(): string
    {
        return $this->timezone ?? config('app.timezone', 'UTC');
    }

    /**
     * Atajo de hasRole('admin'). El rol admin es además super admin: ver el Gate en
     * AuthServiceProvider y docs/permissions.md.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Acceso al back-office (Filament). Módulo opcional: ver docs/backoffice.md.
     *
     * Filament autentica por el guard `web` (sesión), pero los roles y permisos de este modelo
     * son del guard `api`: `$guard_name = 'api'` hace que Spatie los resuelva siempre contra
     * `api`, sea cual sea el guard de la petición. Por eso `can()` sirve desde la sesión web
     * (y respeta al super admin). Se exige además que la cuenta esté activa y no bloqueada.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'backoffice') {
            return false;
        }

        if ($this->trashed() || Blacklist::isBlocked($this->email, $this->identity_document, $this->id)) {
            return false;
        }

        return $this->can('backoffice.access');
    }

    /**
     * Relación con API Keys
     * Alias para apiKeys() del trait HasApiTokens
     */
    public function apiTokens(): HasMany
    {
        return $this->apiKeys();
    }

    /**
     * Relación con ActivityLogs
     * Obtiene todos los logs de actividad relacionados con este usuario
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'user_id');
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class, 'user_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class, 'user_id');
    }

    /**
     * Scope para filtrar usuarios activos (no eliminados)
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Scope para filtrar usuarios inactivos (eliminados)
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeInactive($query)
    {
        return $query->onlyTrashed();
    }

    /**
     * Scope para filtrar por email
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    /**
     * Get the indexable data array for the model.
     * Sobrescribe el método del trait Searchable para personalizar los campos indexables.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $array = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'identity_document' => $this->identity_document,
            'email_verified_at' => $this->email_verified_at ? $this->email_verified_at->timestamp : null,
            'timezone' => $this->timezone,
            'created_at' => $this->created_at->timestamp,
            'updated_at' => $this->updated_at->timestamp,
            'is_admin' => $this->isAdmin(),
            'roles' => $this->roles->pluck('name')->toArray(),
            'role_ids' => $this->roles->pluck('id')->toArray(),
        ];

        return $array;
    }

    /**
     * Campos que deben ser filtrables en Meilisearch.
     * Sobrescribe el método del trait Searchable.
     *
     * @return array<string>
     */
    public function getFilterableAttributes(): array
    {
        return [
            'id',
            'name',
            'email',
            'identity_document',
            'email_verified_at',
            'timezone',
            'is_admin',
            'roles',
            'role_ids',
            'created_at',
        ];
    }

    /**
     * Campos que deben ser ordenables en Meilisearch.
     * Sobrescribe el método del trait Searchable.
     *
     * @return array<string>
     */
    public function getSortableAttributes(): array
    {
        return [
            'name',
            'email',
            'created_at',
            'updated_at',
            'email_verified_at',
        ];
    }
}
