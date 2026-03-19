<?php

namespace App\Enums;

/**
 * ApiKeyScopeEnum
 *
 * Enumeración de scopes posibles para API Keys.
 * Los scopes siguen el formato "resource.action" o "*" para acceso total.
 */
enum ApiKeyScopeEnum: string
{
    // User scopes
    case user_read = 'user.read';
    case user_create = 'user.create';
    case user_update = 'user.update';
    case user_delete = 'user.delete';

    // Role scopes
    case role_read = 'role.read';
    case role_create = 'role.create';
    case role_update = 'role.update';
    case role_delete = 'role.delete';

    // Permission scopes
    case permission_read = 'permission.read';
    case permission_create = 'permission.create';
    case permission_update = 'permission.update';
    case permission_delete = 'permission.delete';

    // ApiKey scopes
    case apikey_read = 'apikey.read';
    case apikey_create = 'apikey.create';
    case apikey_update = 'apikey.update';
    case apikey_delete = 'apikey.delete';

    // Webhook scopes
    case webhook_read = 'webhook.read';
    case webhook_create = 'webhook.create';
    case webhook_update = 'webhook.update';
    case webhook_delete = 'webhook.delete';

    // File scopes
    case file_read = 'file.read';
    case file_create = 'file.create';
    case file_update = 'file.update';
    case file_delete = 'file.delete';

    // Total access
    case all = '*';
}