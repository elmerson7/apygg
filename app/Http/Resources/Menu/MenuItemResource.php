<?php

namespace App\Http\Resources\Menu;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;

class MenuItemResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->getBaseFields(),
            'key' => $this->key,
            'label' => $this->label,
            'route' => $this->route,
            'icon' => $this->icon,
            'permission' => $this->permission,
            'parent_key' => $this->parent_key,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'role_ids' => $this->includeWhenLoaded('roles', $this->roles?->pluck('id')->all()),
        ];
    }
}
