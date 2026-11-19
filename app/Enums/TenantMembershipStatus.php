<?php

namespace App\Enums;

/**
 * Estado de la pertenencia de un usuario a un tenant (`tenant_user.status`, string).
 * Solo `active` da acceso al tenant.
 */
enum TenantMembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';
}
