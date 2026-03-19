<?php

namespace App\Enums;

/**
 * UserStatusEnum
 *
 * Enumeración de estados posibles para un usuario.
 */
enum UserStatusEnum: string
{
    case active = 'active';
    case inactive = 'inactive';
    case banned = 'banned';
}