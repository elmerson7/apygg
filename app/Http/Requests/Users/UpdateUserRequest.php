<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\BaseFormRequest;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Traits\ValidatesIdentityDocument;
use Illuminate\Validation\Rule;

/**
 * UpdateUserRequest
 *
 * Form Request para validación de actualización de usuarios.
 * Los campos permitidos dependen de quién edita (ver `rules()`).
 * Incluye sanitización automática heredada de BaseFormRequest.
 */
class UpdateUserRequest extends BaseFormRequest
{
    use ValidatesIdentityDocument;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = User::findOrFail($this->route('id'));

        return (bool) $this->user()?->can('update', $user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Editar el propio perfil y administrar usuarios (users.update) son cosas distintas:
     * - roles: nunca por aquí; solo con POST /users/{id}/roles (users.manage-roles)
     * - contraseña propia: solo por /auth/change-password (pide la actual)
     * - email: solo con users.update (no hay flujo de verificación aquí)
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('id');
        $actor = $this->user();
        assert($actor instanceof User); // `authorize()` ya exigió un usuario autenticado
        $isSelf = (int) $actor->id === (int) $userId;
        $canManageUsers = $actor->can('users.update');

        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'username' => ['sometimes', 'string', 'max:50', Rule::unique('users', 'username')->ignore($userId)],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => $canManageUsers
                ? ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)]
                : ['prohibited'],
            'password' => $isSelf
                ? ['prohibited']
                : ['sometimes', 'string', 'min:8', StrongPassword::basic()],
            'identity_document' => $this->identityDocumentRules($userId),
            'role_ids' => ['prohibited'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, mixed>
     */
    protected function getCustomMessages(): array
    {
        return [
            'role_ids.prohibited' => 'Los roles se asignan en POST /users/{id}/roles',
            'password.prohibited' => 'Para cambiar tu contraseña usa /auth/change-password',
            'email.prohibited' => 'No tienes permiso para cambiar el email',
            'first_name.string' => 'El nombre debe ser texto',
            'first_name.max' => 'El nombre no puede exceder 100 caracteres',
            'last_name.string' => 'El apellido debe ser texto',
            'last_name.max' => 'El apellido no puede exceder 100 caracteres',
            'username.unique' => 'El username ya está en uso',
            'name.string' => 'El nombre debe ser texto',
            'name.max' => 'El nombre no puede exceder 255 caracteres',
            'email.email' => 'El email debe ser válido',
            'email.unique' => 'El email ya está en uso',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'identity_document.unique' => 'El documento de identidad ya está registrado',
            'identity_document.regex' => 'El documento de identidad no tiene un formato válido',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, mixed>
     */
    protected function getCustomAttributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'password' => 'contraseña',
            'identity_document' => 'documento de identidad',
        ];
    }
}
