<?php

namespace App\Http\Requests\I18n;

use App\Contracts\LocaleResolverInterface;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Idioma y zona horaria propios (PUT /user/locale). `null` = heredar del tenant/instalación.
 */
class UpdateUserLocaleRequest extends BaseFormRequest
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
        ];
    }
}
