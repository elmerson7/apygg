<?php

namespace App\Http\Requests\I18n;

use App\Contracts\LocaleResolverInterface;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Idioma, zona horaria y moneda por defecto del tenant (PUT /tenant/locale). `null` = heredar.
 */
class UpdateTenantLocaleRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(app(LocaleResolverInterface::class)->supportedLocales())],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'currency' => ['sometimes', 'nullable', 'string', Rule::in(app(LocaleResolverInterface::class)->supportedCurrencies())],
        ];
    }
}
