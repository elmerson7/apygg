<?php

namespace App\Contracts;

use App\Exceptions\PrivacyException;
use App\Models\PersonalDataExport;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portabilidad: exportación de los datos personales del propio usuario (docs/privacy.md).
 */
interface PersonalDataExportServiceInterface
{
    /**
     * Encola una exportación. Si ya hay una en curso la devuelve (idempotente).
     *
     * @throws PrivacyException `data_export_rate_limited`
     */
    public function request(User $user): PersonalDataExport;

    /**
     * Genera el ZIP (lo llama el job; idempotente: una exportación ya lista no se rehace).
     */
    public function generate(int $exportId): void;

    public function markFailed(int $exportId, string $error): void;

    /**
     * @return Collection<int, PersonalDataExport>
     */
    public function list(User $user): Collection;

    /**
     * Enlace temporal firmado para descargar (solo el dueño).
     *
     * @throws PrivacyException `data_export_not_ready`, `data_export_expired`
     */
    public function downloadUrl(User $user, int $exportId): string;

    /**
     * Descarga (la firma ya se validó en la ruta). Nunca sirve la exportación de otra persona.
     *
     * @throws PrivacyException `data_export_not_ready`, `data_export_expired`
     */
    public function download(int $exportId, int $userId): StreamedResponse;

    /**
     * Caduca las listas vencidas (borra el ZIP) y borra registros antiguos. Devuelve cuántas caducó.
     */
    public function expireDue(): int;
}
