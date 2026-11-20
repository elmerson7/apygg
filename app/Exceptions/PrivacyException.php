<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Errores de dominio de privacidad. `code` estable (docs/privacy.md): los clientes ramifican por él.
 */
class PrivacyException extends ApiException
{
    /**
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(string $message, int $status, private readonly string $stableCode, array $extensions = [])
    {
        parent::__construct($message, $status, [], $stableCode, null, $extensions);
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->stableCode;
    }

    /**
     * @param  list<array<string, mixed>>  $pending
     */
    public static function consentRequired(array $pending): self
    {
        return new self('Debes aceptar la versión vigente de los documentos legales.', 409, 'consent_required', ['pending' => $pending]);
    }

    public static function consentVersionOutdated(string $key): self
    {
        return new self("La versión del documento «{$key}» ya no es la vigente.", 409, 'consent_version_outdated');
    }

    public static function consentMandatory(string $key): self
    {
        return new self("El consentimiento «{$key}» es obligatorio y no se puede retirar.", 422, 'consent_mandatory');
    }

    public static function documentUnknown(string $key): self
    {
        return new self("No hay un documento vigente «{$key}».", 404, 'legal_document_not_found');
    }

    public static function documentImmutable(): self
    {
        return new self('Los documentos legales y los consentimientos no se modifican ni se borran.', 500, 'privacy_immutable');
    }

    public static function exportRateLimited(int $retryAfterSeconds): self
    {
        return new self('Ya pediste una exportación hace poco.', 429, 'data_export_rate_limited', ['retry_after' => $retryAfterSeconds]);
    }

    public static function exportNotReady(): self
    {
        return new self('La exportación todavía no está lista.', 409, 'data_export_not_ready');
    }

    public static function exportExpired(): self
    {
        return new self('La exportación ha caducado.', 410, 'data_export_expired');
    }

    public static function passwordInvalid(): self
    {
        return new self('La contraseña no es correcta.', 422, 'deletion_password_invalid');
    }

    public static function secondFactorInvalid(): self
    {
        return new self('El segundo factor no es válido.', 422, 'deletion_second_factor_invalid');
    }

    /**
     * @param  list<int>  $tenantIds
     */
    public static function lastOwner(array $tenantIds): self
    {
        return new self('Eres el único dueño de una empresa: transfiere la titularidad o elimínala antes.', 422, 'last_owner', ['tenant_ids' => $tenantIds]);
    }

    public static function lastAdmin(): self
    {
        return new self('No se puede dejar el sistema sin administradores.', 422, 'last_admin');
    }

    public static function deletionNotPending(): self
    {
        return new self('No hay una solicitud de borrado pendiente.', 404, 'deletion_not_pending');
    }
}
