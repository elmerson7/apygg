<?php

namespace App\Contracts\Privacy;

use App\Exceptions\PrivacyException;
use App\Models\User;

/**
 * Gancho del segundo factor al pedir el borrado de cuenta. Por defecto no hace nada; un proyecto con 2FA lo
 * sustituye en un provider (docs/privacy.md).
 */
interface DeletionSecondFactorInterface
{
    /**
     * @throws PrivacyException `deletion_second_factor_invalid`
     */
    public function verify(User $user, ?string $code): void;
}
