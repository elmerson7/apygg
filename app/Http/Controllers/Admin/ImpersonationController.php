<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\ImpersonationServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartImpersonationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Impersonación de soporte (docs/impersonation.md). `start` exige `users.impersonate` y motivo; `stop` lo puede
 * llamar el propio token de impersonación (solo `auth:api`).
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationServiceInterface $impersonation) {}

    public function start(StartImpersonationRequest $request, int $id): JsonResponse
    {
        $target = User::query()->findOrFail($id);
        $actor = $request->user('api');
        abort_unless($actor instanceof User, 401);

        $data = $this->impersonation->start(
            $actor,
            $target,
            $request->input('reason'),
            $request->filled('tenant_id') ? (int) $request->input('tenant_id') : null,
        );

        return $this->sendSuccess($data, 'Impersonación iniciada');
    }

    public function stop(Request $request): JsonResponse
    {
        $user = $request->user('api');
        abort_unless($user instanceof User, 401);

        $this->impersonation->stop($user);

        return $this->sendSuccess(null, 'Impersonación terminada');
    }
}
