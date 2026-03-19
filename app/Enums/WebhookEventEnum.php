<?php

namespace App\Enums;

/**
 * WebhookEventEnum
 *
 * Enumeración de eventos disponibles para webhooks.
 */
enum WebhookEventEnum: string
{
    // User events
    case user_created = 'user.created';
    case user_updated = 'user.updated';
    case user_deleted = 'user.deleted';
    case user_restored = 'user.restored';
    case user_logged_in = 'user.logged_in';
    case user_logged_out = 'user.logged_out';

    // Authorization events
    case role_assigned = 'role.assigned';
    case role_removed = 'role.removed';
    case permission_granted = 'permission.granted';
    case permission_revoked = 'permission.revoked';
}