<?php

namespace App\Services\Mail;

use App\Contracts\BrandResolverInterface;
use App\Contracts\SettingsRegistryInterface;

/**
 * BrandResolver
 *
 * Marca efectiva para los correos: los ajustes `brand.*` (tenant activo -> plataforma, vía
 * `SettingsRegistry`) pisan a `config/brand.php`; lo no definido cae a la config. Sin estado.
 */
class BrandResolver implements BrandResolverInterface
{
    public function __construct(private readonly SettingsRegistryInterface $settings) {}

    public function get(): array
    {
        $colors = (array) config('brand.colors', []);
        $primary = $this->setting('brand.primary_color');

        if ($primary !== null) {
            $colors['primary'] = $primary;
        }

        $social = $this->setting('brand.social');

        return [
            'name' => (string) ($this->setting('brand.name') ?? config('brand.name') ?? config('app.name')),
            'logo_url' => $this->setting('brand.logo_url') ?? config('brand.logo_url'),
            'url' => (string) (config('brand.url') ?: config('app.url')),
            'colors' => array_map('strval', $colors),
            'legal_address' => $this->setting('brand.legal_address') ?? config('brand.legal_address'),
            'support_email' => $this->setting('brand.support_email') ?? config('brand.support_email'),
            'social' => array_filter(
                is_array($social) && $social !== [] ? $social : (array) config('brand.social', []),
                fn ($url) => is_string($url) && str_starts_with($url, 'https://'),
            ),
        ];
    }

    private function setting(string $key): mixed
    {
        $value = $this->settings->get($key);

        return $value === '' || $value === [] ? null : $value;
    }
}
