<?php

namespace App\Auth;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use PHPOpenSourceSaver\JWTAuth\Providers\Storage\Illuminate;

/**
 * Almacén de la lista negra de JWT en un store de caché PROPIO (`jwt.blacklist_store`).
 *
 * Con la caché general, un `cache:clear` (o `Cache::flush()`) vaciaba la lista negra y los tokens revocados
 * volvían a valer. Con Redis, el store `jwt_blacklist` usa una conexión/BD aparte (`REDIS_JWT_DB`), que
 * `cache:clear` no toca. Sin valor configurado se usa el store por defecto (comportamiento anterior).
 * Ver docs/sessions-and-2fa.md §Lista negra.
 */
class JwtBlacklistStorage extends Illuminate
{
    public function __construct(CacheFactory $cache)
    {
        $store = config('jwt.blacklist_store');

        parent::__construct($cache->store(is_string($store) && $store !== '' ? $store : null));
    }
}
