<?php

namespace App\Http\Controllers\Security;

use App\Contracts\AuditServiceInterface;
use App\Contracts\SecurityServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\StoreBlacklistRequest;
use App\Models\Blacklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BlacklistController
 *
 * Gestión de la lista de identidades bloqueadas.
 */
class BlacklistController extends Controller
{
    public function __construct(
        private readonly SecurityServiceInterface $security,
        private readonly AuditServiceInterface $audit,
    ) {}

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
            ->paginate($this->perPage($request, 25));

        return $this->sendPaginated($entries);
    }

    public function store(StoreBlacklistRequest $request): JsonResponse
    {
        $reason = $this->audit->requireReason('user.block', (string) $request->validated('reason'));

        $entry = $this->security->blockIdentity($request->validated(), $this->authUser($request)->id);

        $this->audit->recordAction('user.block', $entry, $reason, ['user_id' => $entry->user_id, 'blocked_email' => $entry->email]);

        return $this->sendSuccess(
            $entry->load(['user:id,name,email', 'addedBy:id,name']),
            'Identidad bloqueada exitosamente',
            201
        );
    }

    public function destroy(Request $request, Blacklist $blacklist): JsonResponse
    {
        $reason = $this->audit->requireReason('user.unblock', $request->input('reason'));

        $blacklist->delete();

        $this->audit->recordAction('user.unblock', $blacklist, $reason, ['user_id' => $blacklist->user_id]);

        if ($blacklist->user_id !== null) {
            Blacklist::forgetBlockCache($blacklist->user_id);
        }

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
