<?php

namespace App\Http\Controllers\Tenancy;

use App\Contracts\TenantMemberServiceInterface;
use App\Http\Controllers\Concerns\UsesActiveTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\IndexTenantMembersRequest;
use App\Http\Requests\Tenancy\UpdateTenantMemberRequest;
use App\Http\Resources\Tenancy\TenantMemberResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Miembros del tenant activo (ruta con middleware `tenant`). El tenant sale del contexto, nunca del cuerpo.
 */
class TenantMemberController extends Controller
{
    use UsesActiveTenant;

    protected ?string $resource = TenantMemberResource::class;

    public function __construct(private readonly TenantMemberServiceInterface $members) {}

    public function index(IndexTenantMembersRequest $request): JsonResponse
    {
        return $this->sendPaginated($this->members->paginate(
            $this->activeTenant($request),
            $request->safe()->only(['status', 'role', 'search']),
            $this->perPage($request),
        ));
    }

    public function update(UpdateTenantMemberRequest $request, int $userId): JsonResponse
    {
        $member = $this->members->changeRole($this->activeTenant($request), $this->actor($request), $userId, (string) $request->validated('role'));

        return $this->sendSuccess($member, 'Rol actualizado');
    }

    public function suspend(Request $request, int $userId): JsonResponse
    {
        $member = $this->members->suspend($this->activeTenant($request), $this->actor($request), $userId);

        return $this->sendSuccess($member, 'Miembro suspendido');
    }

    public function reactivate(Request $request, int $userId): JsonResponse
    {
        $member = $this->members->reactivate($this->activeTenant($request), $this->actor($request), $userId);

        return $this->sendSuccess($member, 'Miembro reactivado');
    }

    public function destroy(Request $request, int $userId): JsonResponse
    {
        $this->members->remove($this->activeTenant($request), $this->actor($request), $userId);

        return $this->sendSuccess(null, 'Miembro eliminado de la empresa');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
