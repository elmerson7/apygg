<?php

namespace App\Http\Controllers\Tenancy;

use App\Contracts\TenantInvitationServiceInterface;
use App\Http\Controllers\Concerns\UsesActiveTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\AcceptTenantInvitationRequest;
use App\Http\Requests\Tenancy\IndexTenantInvitationsRequest;
use App\Http\Requests\Tenancy\StoreTenantInvitationRequest;
use App\Http\Resources\Tenancy\TenantInvitationResource;
use App\Http\Resources\Tenancy\TenantResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantInvitationController extends Controller
{
    use UsesActiveTenant;

    protected ?string $resource = TenantInvitationResource::class;

    public function __construct(private readonly TenantInvitationServiceInterface $invitations) {}

    public function index(IndexTenantInvitationsRequest $request): JsonResponse
    {
        return $this->sendPaginated($this->invitations->paginate(
            $this->activeTenant($request),
            $this->actor($request),
            $request->safe()->only(['status', 'search']),
            $this->perPage($request),
        ));
    }

    public function store(StoreTenantInvitationRequest $request): JsonResponse
    {
        $invitation = $this->invitations->invite(
            $this->activeTenant($request),
            $this->actor($request),
            (string) $request->validated('email'),
            $request->role(),
        );

        return $this->sendSuccess($invitation, 'Invitación enviada', 201);
    }

    public function resend(Request $request, int $id): JsonResponse
    {
        return $this->sendSuccess(
            $this->invitations->resend($this->activeTenant($request), $this->actor($request), $id),
            'Invitación reenviada',
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->sendSuccess(
            $this->invitations->revoke($this->activeTenant($request), $this->actor($request), $id),
            'Invitación revocada',
        );
    }

    /**
     * Aceptar con el token del correo. NO usa el middleware `tenant` (el invitado aún no es miembro).
     */
    public function accept(AcceptTenantInvitationRequest $request): JsonResponse
    {
        $tenant = $this->invitations->accept($this->actor($request), (string) $request->validated('token'));

        return $this->sendSuccess(new TenantResource($tenant), 'Invitación aceptada');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
