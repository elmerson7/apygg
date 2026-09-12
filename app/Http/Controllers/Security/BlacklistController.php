<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Security\StoreBlacklistRequest;
use App\Models\Blacklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BlacklistController
 *
 * Gestión de la lista de identidades bloqueadas.
 */
class BlacklistController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $entries = Blacklist::query()
            ->with(['user:id,name,email', 'addedBy:id,name'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub->where('email', 'ILIKE', $search)
                    ->orWhere('identity_document', 'ILIKE', $search)
                    ->orWhere('name', 'ILIKE', $search));
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return $this->sendPaginated($entries);
    }

    public function store(StoreBlacklistRequest $request): JsonResponse
    {
        $entry = Blacklist::create([
            ...$request->validated(),
            'added_by' => $request->user()->id,
        ]);

        return $this->sendSuccess(
            $entry->load(['user:id,name,email', 'addedBy:id,name']),
            'Identidad bloqueada exitosamente',
            201
        );
    }

    public function destroy(Blacklist $blacklist): JsonResponse
    {
        $blacklist->delete();

        return $this->sendSuccess(null, 'Bloqueo eliminado exitosamente');
    }

    /**
     * Comprobar si una identidad está bloqueada.
     */
    public function check(Request $request): JsonResponse
    {
        $entry = Blacklist::findFor(
            email: $request->string('email')->toString() ?: null,
            identityDocument: $request->string('identity_document')->toString() ?: null,
        );

        return $this->sendSuccess([
            'blocked' => $entry !== null,
            'reason' => $entry?->reason,
            'blocked_at' => $entry?->created_at?->toIso8601String(),
        ], 'Comprobación realizada exitosamente');
    }
}
