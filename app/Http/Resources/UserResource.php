<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class UserResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->formatDate($this->email_verified_at),
            'created_at' => $this->formatDate($this->created_at),
            'updated_at' => $this->formatDate($this->updated_at),
            
            // Campos sensibles solo para el propietario
            ...$this->whenOwner($request, $this->id, [
                'remember_token' => $this->when(false, null), // Nunca exponer
                // Aquí puedes agregar más campos privados en el futuro
            ]),
            
            // Campos adicionales para usuarios autenticados
            ...$this->whenAuthenticated($request, [
                // Campos que solo ven usuarios logueados
                // 'last_active_at' => $this->formatDate($this->last_active_at),
            ]),
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return array_merge(parent::with($request), [
            'links' => [
                'self' => url("/users/{$this->id}"),
                'update' => url("/users/{$this->id}"),
                'me' => url("/users/me"),
            ],
        ]);
    }
}
