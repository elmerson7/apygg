<?php

namespace App\Exceptions\Tenancy;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;

/**
 * Errores de dominio del alta de empresas, invitaciones y miembros. El `code` es estable
 * (docs/tenant-onboarding.md): los clientes ramifican por él, no por el mensaje.
 */
class TenantOnboardingException extends ApiException
{
    public function __construct(string $message, int $status, private readonly string $stableCode)
    {
        parent::__construct($message, $status, [], $stableCode);
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->stableCode;
    }

    public static function signupDisabled(): self
    {
        return new self('El alta de empresas no está habilitada.', 403, 'tenant_signup_disabled');
    }

    public static function tenantLimitReached(): self
    {
        return new self('Has alcanzado el máximo de empresas que puedes crear.', 422, 'tenant_limit_reached');
    }

    public static function invitationInvalid(): self
    {
        return new self('La invitación no existe o el enlace no es válido.', 404, 'invitation_invalid');
    }

    public static function invitationExpired(): self
    {
        return new self('La invitación ha caducado.', 410, 'invitation_expired');
    }

    public static function invitationRevoked(): self
    {
        return new self('La invitación fue revocada.', 410, 'invitation_revoked');
    }

    public static function invitationUsed(): self
    {
        return new self('La invitación ya fue utilizada.', 409, 'invitation_used');
    }

    public static function invitationEmailMismatch(): self
    {
        return new self('Esta invitación no es para tu cuenta.', 403, 'invitation_email_mismatch');
    }

    public static function invitationAlreadyPending(): self
    {
        return new self('Ya hay una invitación pendiente para ese correo.', 409, 'invitation_already_pending');
    }

    public static function invitationLimitReached(): self
    {
        return new self('Se alcanzó el máximo de invitaciones pendientes.', 422, 'invitation_limit_reached');
    }

    public static function alreadyMember(): self
    {
        return new self('La persona ya es miembro de esta empresa.', 409, 'already_member');
    }

    public static function roleInsufficient(): self
    {
        return new self('Tu rol en la empresa no permite esta operación.', 403, 'tenant_role_insufficient');
    }

    public static function lastOwner(): self
    {
        return new self('La empresa debe conservar al menos un dueño activo.', 422, 'last_owner');
    }

    public static function memberNotFound(): self
    {
        return new self('Miembro no encontrado.', 404, 'not_found');
    }

    public static function cannotSuspendSelf(): self
    {
        return new self('No puedes suspenderte a ti mismo.', 422, 'cannot_suspend_self');
    }

    public static function invalidState(string $message): self
    {
        return new self($message, 422, 'invalid_state');
    }
}
