<?php

namespace App\Http\Controllers\I18n;

use App\Contracts\LocaleResolverInterface;
use App\Contracts\LocaleSettingsServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\I18n\UpdateTenantLocaleRequest;
use App\Http\Requests\I18n\UpdateUserLocaleRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Idioma, zona horaria y moneda: los del usuario en sesión (`/user/locale`) y los del tenant
 * activo (`/tenant/locale`). Devuelve siempre lo guardado (`null` = hereda) y lo efectivo.
 */
class LocaleController extends Controller
{
    public function __construct(
        private readonly LocaleResolverInterface $resolver,
        private readonly LocaleSettingsServiceInterface $settings,
        private readonly TenantContext $tenants,
    ) {}

    public function showUser(Request $request): JsonResponse
    {
        return $this->sendSuccess($this->userPayload($request->user()), __('messages.locale.show'));
    }

    public function updateUser(UpdateUserLocaleRequest $request): JsonResponse
    {
        $user = $this->settings->updateUser($request->user(), $request->validated());

        return $this->sendSuccess($this->userPayload($user), __('messages.locale.updated'));
    }

    public function showTenant(): JsonResponse
    {
        return $this->sendSuccess($this->tenantPayload($this->tenants->require()), __('messages.locale.tenant_show'));
    }

    public function updateTenant(UpdateTenantLocaleRequest $request): JsonResponse
    {
        $tenant = $this->settings->updateTenant($this->tenants->require(), $request->user(), $request->validated());

        return $this->sendSuccess($this->tenantPayload($tenant), __('messages.locale.tenant_updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'stored' => ['locale' => $user->locale, 'timezone' => $user->timezone],
            'effective' => $this->resolver->effective(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantPayload(Tenant $tenant): array
    {
        return [
            'stored' => ['locale' => $tenant->locale, 'timezone' => $tenant->timezone, 'currency' => $tenant->currency],
            'effective' => $this->resolver->effective(),
        ];
    }
}
