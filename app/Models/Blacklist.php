<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Blacklist Model
 *
 * Lista de identidades bloqueadas: correos, documentos o usuarios concretos a
 * los que no se les permite registrarse ni operar.
 *
 * Es distinta del bloqueo por IP (`config/security.php` → `ip_blacklist`), que
 * actúa sobre la conexión: esta actúa sobre la persona, que puede volver desde
 * otra IP.
 *
 * Siempre queda registrado quién la añadió y por qué: un bloqueo sin motivo ni
 * responsable es imposible de revisar meses después.
 *
 * @property int $id
 * @property string|null $email
 * @property string|null $identity_document
 * @property string|null $name
 * @property string $reason
 */
class Blacklist extends Model
{
    protected $table = 'blacklists';

    protected $fillable = [
        'user_id',
        'email',
        'identity_document',
        'name',
        'reason',
        'added_by',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * ¿Está bloqueada alguna de estas identidades?
     *
     * La comparación de correo es insensible a mayúsculas: `Ana@X.com` y
     * `ana@x.com` son la misma persona.
     */
    public static function isBlocked(?string $email = null, ?string $identityDocument = null, ?int $userId = null): bool
    {
        if ($email === null && $identityDocument === null && $userId === null) {
            return false;
        }

        return self::query()
            ->where(function (Builder $query) use ($email, $identityDocument, $userId) {
                if ($email !== null) {
                    $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
                }

                if ($identityDocument !== null) {
                    $query->orWhere('identity_document', $identityDocument);
                }

                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->exists();
    }

    /**
     * Registro que bloquea a esta identidad, si lo hay.
     */
    public static function findFor(?string $email = null, ?string $identityDocument = null, ?int $userId = null): ?self
    {
        if ($email === null && $identityDocument === null && $userId === null) {
            return null;
        }

        return self::query()
            ->where(function (Builder $query) use ($email, $identityDocument, $userId) {
                if ($email !== null) {
                    $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
                }

                if ($identityDocument !== null) {
                    $query->orWhere('identity_document', $identityDocument);
                }

                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->first();
    }

    /** TTL (segundos) de la caché de «¿está bloqueado este usuario?» que consultan los guards. */
    public const BLOCK_CACHE_TTL = 60;

    /**
     * ¿Está bloqueado este usuario (por id, correo o documento)? Barato: lo consultan los guards en
     * cada petición, así que se cachea unos segundos; `forgetBlockCache()` lo invalida al bloquear.
     */
    public static function blocksUser(User $user): bool
    {
        return (bool) Cache::remember(
            self::blockCacheKey($user->id),
            self::BLOCK_CACHE_TTL,
            fn () => self::isBlocked($user->email, $user->identity_document, $user->id),
        );
    }

    public static function forgetBlockCache(int $userId): void
    {
        Cache::forget(self::blockCacheKey($userId));
    }

    protected static function blockCacheKey(int $userId): string
    {
        return "blacklist:user:{$userId}";
    }
}
