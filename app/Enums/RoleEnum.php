<?php

namespace App\Enums;

/**
 * RoleEnum
 *
 * Enumeración de roles posibles en el sistema.
 */
enum RoleEnum: string
{
    case admin = 'admin';
    case user = 'user';
    case guest = 'guest';
}