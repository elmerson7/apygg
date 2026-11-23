<?php

namespace App\Http\Requests\Users;

use App\Contracts\LocaleResolverInterface;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateProfileRequest
 *
 * Edición del perfil del usuario en sesión (PUT /user/profile).
 *
 * Solo campos de perfil. El email, la contraseña (/auth/change-password), los
 * roles y el documento de identidad NO se cambian por aquí: si llegan, se
 * rechazan con 422 en lugar de ignorarse en silencio.
 */
class UpdateProfileRequest extends BaseFormRequest
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
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'username' => ['sometimes', 'string', 'min:3', 'max:50', Rule::unique('users', 'username')->ignore($this->user()?->id)],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(app(LocaleResolverInterface::class)->supportedLocales())],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'social_links' => ['sometimes', 'nullable', 'array'],
            'social_links.*' => ['nullable', 'string', 'max:255'],
            // No se cambian desde el perfil
            'email' => ['prohibited'],
            'password' => ['prohibited'],
            'role_ids' => ['prohibited'],
            'roles' => ['prohibited'],
            'identity_document' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCustomMessages(): array
    {
        return [
            'email.prohibited' => 'El email no se cambia desde el perfil',
            'password.prohibited' => 'Para cambiar tu contraseña usa /auth/change-password',
            'role_ids.prohibited' => 'Los roles no se cambian desde el perfil',
            'roles.prohibited' => 'Los roles no se cambian desde el perfil',
            'identity_document.prohibited' => 'El documento de identidad no se cambia desde el perfil',
            'username.unique' => 'El username ya está en uso',
        ];
    }
}
