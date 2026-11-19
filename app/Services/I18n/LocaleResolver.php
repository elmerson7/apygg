<?php

namespace App\Services\I18n;

use App\Contracts\LocaleResolverInterface;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * LocaleResolver
 *
 * Calcula idioma, zona horaria y moneda efectivos. No guarda estado: lee el usuario del guard
 * `api`, el `TenantContext` (scoped) y la petición en curso, así que es seguro con Octane.
 */
class LocaleResolver implements LocaleResolverInterface
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function locale(): string
    {
        $user = $this->currentUser();
        $tenant = $this->tenants->current();

        return $this->supported($user?->locale)
            ?? $this->supported($tenant?->locale)
            ?? $this->fromHeader(request())
            ?? $this->defaultLocale();
    }

    public function timezone(): string
    {
        return $this->timezoneFor($this->currentUser(), $this->tenants->current());
    }

    public function currency(): string
    {
        $currency = $this->tenants->current()?->currency;

        return is_string($currency) && in_array($currency, $this->supportedCurrencies(), true)
            ? $currency
            : (string) config('i18n.default_currency', 'USD');
    }

    public function localeFor(?User $user, ?Tenant $tenant = null): string
    {
        $tenant ??= $this->tenants->current();

        return $this->supported($user?->locale)
            ?? $this->supported($tenant?->locale)
            ?? $this->defaultLocale();
    }

    public function timezoneFor(?User $user, ?Tenant $tenant = null): string
    {
        $tenant ??= $this->tenants->current();

        return $this->validTimezone($user?->timezone)
            ?? $this->validTimezone($tenant?->timezone)
            ?? $this->validTimezone((string) config('i18n.default_timezone', 'UTC'))
            ?? 'UTC';
    }

    public function supportedLocales(): array
    {
        /** @var list<string> $locales */
        $locales = array_values((array) config('i18n.locales', []));

        return $locales !== [] ? $locales : [$this->defaultLocale()];
    }

    public function defaultLocale(): string
    {
        $default = (string) config('i18n.default_locale', 'es');

        return in_array($default, $this->supportedLocales(), true) ? $default : $this->supportedLocales()[0];
    }

    public function isSupportedLocale(?string $locale): bool
    {
        return $locale !== null && in_array($locale, $this->supportedLocales(), true);
    }

    public function effective(): array
    {
        return [
            'locale' => $this->locale(),
            'timezone' => $this->timezone(),
            'currency' => $this->currency(),
            'supported_locales' => $this->supportedLocales(),
        ];
    }

    private function currentUser(): ?User
    {
        $user = auth('api')->user();

        return $user instanceof User ? $user : null;
    }

    private function supported(?string $locale): ?string
    {
        return $this->isSupportedLocale($locale) ? $locale : null;
    }

    private function validTimezone(?string $timezone): ?string
    {
        return is_string($timezone) && $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : null;
    }

    /**
     * Primer idioma soportado de Accept-Language (por calidad); `es-PE` cuenta como `es`.
     */
    private function fromHeader(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $code = strtolower(explode('_', str_replace('-', '_', $language))[0]);

            if ($this->isSupportedLocale($code)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function supportedCurrencies(): array
    {
        $known = array_keys((array) config('currencies.exponents', []));
        $allowed = (array) config('i18n.currencies', []);

        return array_values($allowed === [] ? $known : array_intersect($allowed, $known));
    }
}
