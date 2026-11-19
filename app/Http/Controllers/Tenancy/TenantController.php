<?php

namespace App\Http\Controllers\Tenancy;

use App\Contracts\TenantOnboardingServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\StoreTenantRequest;
use App\Http\Resources\Tenancy\TenantResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    protected ?string $resource = TenantResource::class;

    public function __construct(private readonly TenantOnboardingServiceInterface $onboarding) {}

    /**
     * Alta de empresa: el usuario autenticado queda como dueño.
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tenant = $this->onboarding->createTenant($user, [
            'name' => (string) $request->validated('name'),
            'slug' => (string) $request->validated('slug'),
        ]);

        $mine = $this->onboarding->tenantsOf($user)->firstWhere('id', $tenant->id) ?? $tenant;

        $message = $tenant->isActive() ? 'Empresa creada' : 'Empresa creada; pendiente de aprobación';

        return $this->sendSuccess($mine, $message, 201);
    }

    /**
     * Empresas del usuario autenticado.
     */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->sendSuccess(TenantResource::collection($this->onboarding->tenantsOf($user)), 'Empresas del usuario');
    }
}
