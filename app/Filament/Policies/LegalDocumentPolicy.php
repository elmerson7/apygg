<?php

namespace App\Filament\Policies;

use App\Models\User;

/** Documentos legales: ver `legal-documents.read`; publicar una versión nueva `legal-documents.manage`. Inmutables: no se editan ni se borran. */
class LegalDocumentPolicy extends PermissionBasedPolicy
{
    protected function readPermission(): string
    {
        return 'legal-documents.read';
    }

    public function create(User $user): bool
    {
        return $user->can('legal-documents.manage');
    }
}
