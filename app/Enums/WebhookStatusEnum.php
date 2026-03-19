<?php

namespace App\Enums;

/**
 * WebhookStatusEnum
 *
 * Enumeración de estados posibles para webhooks.
 */
enum WebhookStatusEnum: string
{
    case active = 'active';
    case inactive = 'inactive';
    case paused = 'paused';
}