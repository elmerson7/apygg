<?php

namespace App\Contracts;

use App\Models\Tenant;
use App\Models\User;

/**
 * Idioma, zona horaria y moneda EFECTIVOS del contexto actual (docs/i18n.md).
 *
 * Precedencia: usuario -> tenant -> cabecera Accept-Language (solo el idioma) -> instalación.
 * Sin estado entre peticiones: se calcula a partir del usuario autenticado, el `TenantContext`
 * y la petición en curso cada vez que se pide.
 */
interface LocaleResolverInterface
{
    /** Idioma efectivo del contexto actual (siempre uno de `supportedLocales()`). */
    public function locale(): string;

    /** Zona horaria IANA efectiva (solo presentación; la BD y la API van en UTC). */
    public function timezone(): string;

    /** Moneda ISO 4217 efectiva. */
    public function currency(): string;

    /** Idioma de un destinatario (sin cabecera: correos y notificaciones en cola). */
    public function localeFor(?User $user, ?Tenant $tenant = null): string;

    public function timezoneFor(?User $user, ?Tenant $tenant = null): string;

    /** @return list<string> */
    public function supportedLocales(): array;

    public function defaultLocale(): string;

    /**
     * Monedas aceptables: las de `config/currencies.php` (las que `Money` maneja), restringidas por
     * `config('i18n.currencies')` si no está vacío.
     *
     * @return list<string>
     */
    public function supportedCurrencies(): array;

    public function isSupportedLocale(?string $locale): bool;

    /**
     * Valores efectivos para exponer al front.
     *
     * @return array{locale: string, timezone: string, currency: string, supported_locales: list<string>}
     */
    public function effective(): array;
}
