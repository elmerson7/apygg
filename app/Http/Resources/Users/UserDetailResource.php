<?php

namespace App\Http\Resources\Users;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * UserDetailResource
 *
 * Resource para transformación completa de datos de usuario.
 * Incluye permisos efectivos (combinando permisos de roles y permisos directos),
 * tokens API y más información detallada.
 * Usado para vistas detalladas de usuario.
 *
 * @package App\Http\Resources\Users
 */
class UserDetailResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $currentUser = $request->user();

        // Determinar si el usuario actual puede ver información detallada
        $canViewDetails = $currentUser && ($currentUser->id === $user->id || $currentUser->hasPermission('users.read'));

        $data = array_merge($this->getBaseFields(), [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $this->formatDate($user->email_verified_at),

            // Roles siempre visibles (información básica)
            'roles' => $this->whenLoaded('roles', function () use ($user) {
                return $user->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'display_name' => $role->display_name,
                        'description' => $role->description,
                    ];
                });
            }),

            // Permisos directos asignados al usuario (solo si puede ver detalles)
            'direct_permissions' => $this->when(
                $canViewDetails && $user->relationLoaded('permissions'),
                function () use ($user) {
                    return $user->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'display_name' => $permission->display_name,
                            'resource' => $permission->resource,
                            'action' => $permission->action,
                            'description' => $permission->description,
                        ];
                    });
                }
            ),

            // Permisos efectivos (combinando permisos de roles + permisos directos)
            'effective_permissions' => $this->when(
                $canViewDetails && ($user->relationLoaded('roles') || $user->relationLoaded('permissions')),
                function () use ($user) {
                    return $this->getEffectivePermissions($user);
                }
            ),

            // API Tokens (solo para el propio usuario o admin)
            'api_tokens' => $this->when(
                $canViewDetails && $user->relationLoaded('apiTokens'),
                function () use ($user) {
                    return $user->apiTokens->map(function ($token) {
                        return [
                            'id' => $token->id,
                            'name' => $token->name,
                            'last_used_at' => $this->formatDate($token->last_used_at),
                            'expires_at' => $this->formatDate($token->expires_at),
                            'created_at' => $this->formatDate($token->created_at),
                        ];
                    });
                }
            ),

            // Historial de actividad (solo si se solicita explícitamente con eager loading)
            'activity_logs' => $this->whenLoaded('activityLogs', function () use ($user) {
                return $user->activityLogs->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'model_type' => $log->model_type,
                        'model_id' => $log->model_id,
                        'description' => $log->description ?? null,
                        'created_at' => $this->formatDate($log->created_at),
                    ];
                });
            }),

            // Información adicional solo para el propio usuario o admin
            'is_admin' => $this->when($canViewDetails, fn() => $user->isAdmin()),
            'last_login_at' => $this->when($canViewDetails, fn() => $this->formatDate($user->last_login_at ?? null)),

            // Timestamps
            'created_at' => $this->formatDate($user->created_at),
            'updated_at' => $this->formatDate($user->updated_at),
            'deleted_at' => $this->formatDate($user->deleted_at),
        ]);

        return $data;
    }

    /**
     * Obtener permisos efectivos del usuario
     * Combina permisos de roles y permisos directos, eliminando duplicados
     *
     * @param \App\Models\User $user
     * @return array
     */
    protected function getEffectivePermissions($user): array
    {
        $permissions = collect();

        // Obtener permisos de roles
        if ($user->relationLoaded('roles')) {
            foreach ($user->roles as $role) {
                if ($role->relationLoaded('permissions')) {
                    $permissions = $permissions->merge($role->permissions);
                }
            }
        }

        // Agregar permisos directos
        if ($user->relationLoaded('permissions')) {
            $permissions = $permissions->merge($user->permissions);
        }

        // Eliminar duplicados por ID y formatear
        return $permissions->unique('id')->map(function ($permission) {
            return [
                'id' => $permission->id,
                'name' => $permission->name,
                'display_name' => $permission->display_name,
                'resource' => $permission->resource,
                'action' => $permission->action,
                'description' => $permission->description,
            ];
        })->values()->toArray();
    }
}
