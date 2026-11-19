<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MenuItem Model
 *
 * Ítem del menú del panel, configurable desde la BD.
 *
 * La visibilidad se resuelve en dos niveles:
 *
 *   1. Por **permiso** (`permission`): el ítem se ve si el usuario tiene ese
 *      permiso. Es el modo recomendado — el menú queda derivado de lo que el
 *      usuario realmente puede hacer, así que no se desincroniza con la
 *      autorización real.
 *   2. Por **rol** (tabla `menu_item_role`): asignación explícita, para ítems
 *      que no se corresponden con un permiso concreto.
 *
 * Un ítem sin permiso y sin roles asignados no se muestra a nadie: es el
 * default seguro.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string|null $route
 * @property string|null $icon
 * @property string|null $permission
 * @property string|null $parent_key
 * @property int $sort_order
 * @property bool $is_active
 */
class MenuItem extends Model
{
    protected $table = 'menu_items';

    protected $fillable = [
        'key',
        'label',
        'route',
        'icon',
        'permission',
        'parent_key',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Roles con visibilidad explícita sobre este ítem.
     */
    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'menu_item_role', 'menu_item_id', 'role_id');
    }

    /**
     * Nota: la firma debe coincidir con la del Model base (sin tipar), o PHP
     * lanza un error fatal por estrechar la firma del padre.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Ítems hijos, enlazados por `parent_key`.
     *
     * @return HasMany<self, $this>
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_key', 'key');
    }
}
