<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * UserServiceInterface
 *
 * Contrato para el servicio de gestión de usuarios.
 */
interface UserServiceInterface
{
    /**
     * Crear un nuevo usuario
     *
     * @param  array  $data  Datos del usuario
     * @param  array|null  $roleIds  IDs de roles a asignar (opcional)
     * @return User
     */
    public function create(array $data, ?array $roleIds = null): User;

    /**
     * Actualizar un usuario existente
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $data  Datos a actualizar
     * @return User
     */
    public function update(string $userId, array $data): User;

    /**
     * Actualizar preferencias del usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $preferences  Nuevas preferencias
     * @return User
     */
    public function updatePreferences(string $userId, array $preferences): User;

    /**
     * Eliminar un usuario (soft delete)
     *
     * @param  string  $userId  ID del usuario
     * @return bool
     */
    public function delete(string $userId): bool;

    /**
     * Restaurar un usuario eliminado
     *
     * @param  string  $userId  ID del usuario
     * @return User
     */
    public function restore(string $userId): User;

    /**
     * Buscar un usuario por ID
     *
     * @param  string  $userId  ID del usuario
     * @return User
     */
    public function find(string $userId): User;

    /**
     * Listar usuarios con paginación y filtros
     *
     * @param  array  $filters  Filtros ['search', 'per_page', 'page', 'include', 'role', 'exclude_roles']
     * @return LengthAwarePaginator
     */
    public function list(array $filters = []): LengthAwarePaginator;

    /**
     * Asignar roles a un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $roleIds  IDs de roles a asignar
     * @return User
     */
    public function assignRoles(string $userId, array $roleIds): User;

    /**
     * Remover un rol de un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  string  $roleId  ID del rol a remover
     * @return User
     */
    public function removeRole(string $userId, string $roleId): User;

    /**
     * Asignar permisos directos a un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  array  $permissionIds  IDs de permisos a asignar
     * @return User
     */
    public function assignPermissions(string $userId, array $permissionIds): User;

    /**
     * Remover un permiso directo de un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  string  $permissionId  ID del permiso a remover
     * @return User
     */
    public function removePermission(string $userId, string $permissionId): User;

    /**
     * Obtener historial de actividad de un usuario
     *
     * @param  string  $userId  ID del usuario
     * @param  int     $perPage  Elementos por página
     * @return LengthAwarePaginator
     */
    public function getActivityLogs(string $userId, int $perPage = 20): LengthAwarePaginator;
}