<?php

namespace App\Http\Resources\Users;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * UserResource
 *
 * Resource para transformación básica de datos de usuario.
 * Oculta información sensible y permite inclusión condicional de relaciones.
 * Usado para listados y respuestas básicas.
 */
class UserResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return array_merge($this->getBaseFields(), [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $this->formatDate($user->email_verified_at),
            'identity_document' => $user->identity_document,

            // Relaciones opcionales (solo si se cargan con eager loading)
            'roles' => $this->whenLoaded('roles', function () use ($user) {
                return $user->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'display_name' => $role->display_name,
                    ];
                });
            }),

            'permissions' => $this->whenLoaded('permissions', function () use ($user) {
                return $user->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'display_name' => $permission->display_name,
                    ];
                });
            }),

            // Timestamps
            'created_at' => $this->formatDate($user->created_at),
            'updated_at' => $this->formatDate($user->updated_at),
        ]);
    }
}
