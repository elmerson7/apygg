<?php

namespace App\Contracts;

use App\Exceptions\PrivacyException;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Documentos legales versionados y consentimientos de usuario (docs/privacy.md).
 */
interface ConsentServiceInterface
{
    /**
     * Publica una versión (o una traducción de una versión ya publicada: hereda su `effective_at`).
     *
     * @param  array{key: string, version: string, locale: string, title: string, body: string, effective_at?: string|null}  $data
     */
    public function publish(array $data, ?User $author): LegalDocument;

    /**
     * Todas las versiones (administración), más reciente primero.
     *
     * @param  array<string, mixed>  $filters  key, locale
     * @return LengthAwarePaginator<int, LegalDocument>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Versión vigente de cada clave, en el idioma pedido (o el de reserva, o el primero disponible).
     *
     * @return list<array<string, mixed>>
     */
    public function current(?string $locale = null): array;

    /**
     * @return array<string, mixed>
     *
     * @throws PrivacyException `legal_document_not_found`
     */
    public function currentFor(string $key, ?string $locale = null): array;

    /**
     * Estado del usuario por documento vigente: `accepted|withdrawn|pending|outdated`.
     *
     * @return list<array<string, mixed>>
     */
    public function stateFor(User $user): array;

    /**
     * Documentos OBLIGATORIOS cuya versión vigente el usuario no tiene aceptada.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFor(User $user, ?string $locale = null): array;

    /**
     * Acepta las versiones indicadas (idempotente). Solo vale la vigente.
     *
     * @param  list<array{key: string, version: string}>  $documents
     *
     * @throws PrivacyException `consent_version_outdated`, `legal_document_not_found`
     */
    public function accept(User $user, array $documents, ?string $ip, ?string $userAgent, ?string $locale = null): void;

    /**
     * Retira un consentimiento opcional (idempotente).
     *
     * @throws PrivacyException `consent_mandatory`, `legal_document_not_found`
     */
    public function withdraw(User $user, string $key, ?string $ip, ?string $userAgent): void;
}
