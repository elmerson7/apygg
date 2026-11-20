<?php

namespace App\Contracts;

use App\Exceptions\PrivacyException;
use App\Models\AccountDeletionRequest;
use App\Models\User;

/**
 * Derecho de supresión: borrado de cuenta con periodo de gracia (docs/privacy.md).
 */
interface AccountDeletionServiceInterface
{
    /**
     * Pide el borrado. Exige la contraseña (y el segundo factor si hay gancho). Idempotente: con una solicitud
     * pendiente la devuelve sin crear otra.
     *
     * @throws PrivacyException `deletion_password_invalid`, `last_owner`, `last_admin`
     */
    public function request(User $user, string $password, ?string $secondFactor, ?string $ip): AccountDeletionRequest;

    /**
     * @throws PrivacyException `deletion_not_pending`
     */
    public function cancel(User $user): AccountDeletionRequest;

    public function pending(User $user): ?AccountDeletionRequest;

    /**
     * Ids de solicitudes vencidas y por ejecutar.
     *
     * @return list<int>
     */
    public function dueIds(): array;

    /**
     * Ejecuta una solicitud vencida (lo llama el job; idempotente: lo ya completado o cancelado no hace nada).
     */
    public function execute(int $requestId): void;

    public function markFailed(int $requestId, string $reason): void;

    /**
     * Borra solicitudes terminadas más antiguas que la retención. Devuelve cuántas.
     */
    public function purgeFinished(): int;
}
