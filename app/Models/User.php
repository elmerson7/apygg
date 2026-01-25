<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Logs\ActivityLog;
use App\Traits\HasApiTokens;
use App\Traits\LogsActivity;
use App\Traits\SoftDeletesWithUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, LogsActivity, Notifiable, SoftDeletesWithUser;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'deleted_by', // Para SoftDeletesWithUser trait
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
        'deleted_by' => 'string', // UUID del usuario que eliminó (para SoftDeletesWithUser)
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
        return [];
    }

    /**
     * Relación muchos-a-muchos con Role
     *
     * Un usuario puede tener múltiples roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'user_role',
            'user_id',
            'role_id'
        )->withTimestamps();
    }

    /**
     * Relación muchos-a-muchos con Permission (permisos directos)
     *
     * Un usuario puede tener permisos asignados directamente.
     * Estos permisos sobrescriben los permisos heredados de roles.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'user_permission',
            'user_id',
            'permission_id'
        )->withTimestamps();
    }

    /**
     * Verificar si el usuario tiene un permiso específico
     * Verifica primero permisos directos, luego permisos de roles
     *
     * @param  string  $permissionName  Nombre del permiso a verificar
     */
    public function hasPermission(string $permissionName): bool
    {
        // Verificar permisos directos primero
        if ($this->permissions()->where('name', $permissionName)->exists()) {
            return true;
        }

        // Verificar permisos a través de roles
        return $this->roles()->whereHas('permissions', function ($query) use ($permissionName) {
            $query->where('name', $permissionName);
        })->exists();
    }

    /**
     * Verificar si el usuario tiene alguno de los permisos especificados
     *
     * @param  array<string>  $permissionNames  Array de nombres de permisos
     */
    public function hasAnyPermission(array $permissionNames): bool
    {
        foreach ($permissionNames as $permissionName) {
            if ($this->hasPermission($permissionName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si el usuario tiene todos los permisos especificados
     *
     * @param  array<string>  $permissionNames  Array de nombres de permisos
     */
    public function hasAllPermissions(array $permissionNames): bool
    {
        foreach ($permissionNames as $permissionName) {
            if (! $this->hasPermission($permissionName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verificar si el usuario tiene un rol específico
     *
     * @param  string  $roleName  Nombre del rol
     */
    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    /**
     * Verificar si el usuario tiene alguno de los roles especificados
     *
     * @param  array<string>  $roleNames  Array de nombres de roles
     */
    public function hasAnyRole(array $roleNames): bool
    {
        return $this->roles()->whereIn('name', $roleNames)->exists();
    }

    /**
     * Verificar si el usuario es administrador
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
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

    /**
     * Scope para filtrar usuarios activos (no eliminados)
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Scope para filtrar usuarios inactivos (eliminados)
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInactive($query)
    {
        return $query->onlyTrashed();
    }

    /**
     * Scope para filtrar por email
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    /**
     * Scope para filtrar usuarios por rol
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string|array  $roleName  Nombre del rol o array de nombres
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByRole($query, string|array $roleName)
    {
        if (is_array($roleName)) {
            return $query->whereHas('roles', function ($q) use ($roleName) {
                $q->whereIn('name', $roleName);
            });
        }

        return $query->whereHas('roles', function ($q) use ($roleName) {
            $q->where('name', $roleName);
        });
    }
}
