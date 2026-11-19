<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends BaseFormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            // El middleware `tenant` trata un identificador solo numérico como id: el slug lleva al menos una letra.
            'slug' => [
                'required', 'string', 'min:3', 'max:63',
                'regex:/^(?=.*[a-z])[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn((array) config('multi-tenancy.onboarding.signup.reserved_slugs', [])),
                Rule::unique('tenants', 'slug'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCustomMessages(): array
    {
        return [
            'slug.regex' => 'El identificador solo admite minúsculas, números y guiones (con al menos una letra).',
            'slug.not_in' => 'Ese identificador está reservado.',
        ];
    }
}
