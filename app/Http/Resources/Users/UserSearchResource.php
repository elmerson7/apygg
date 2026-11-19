<?php

namespace App\Http\Resources\Users;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * UserSearchResource
 *
 * Vista mínima de un usuario para la búsqueda global: solo lo necesario para
 * identificarlo. El email aparece únicamente a quien administra usuarios
 * (users.update); el documento de identidad y los roles nunca salen por aquí.
 */
class UserSearchResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $viewer = $request->user();

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
        ];

        if ($viewer && $viewer->hasPermission('users.update')) {
            $data['email'] = $user->email;
        }

        return $data;
    }
}
