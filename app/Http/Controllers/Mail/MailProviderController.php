<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreMailProviderRequest;
use App\Http\Requests\Mail\UpdateMailProviderRequest;
use App\Http\Resources\Mail\MailProviderResource;
use App\Models\Mail\MailProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MailProviderController
 *
 * CRUD de proveedores de correo (Resend, SMTP, SES…).
 */
class MailProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $providers = MailProvider::query()
            ->withCount('accounts')
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 15));

        return $this->sendPaginated($providers);
    }

    public function store(StoreMailProviderRequest $request): JsonResponse
    {
        $provider = MailProvider::create($request->validated());

        return $this->sendSuccess(
            new MailProviderResource($provider),
            'Proveedor de correo creado exitosamente',
            201
        );
    }

    public function show(MailProvider $mailProvider): JsonResponse
    {
        return $this->sendSuccess(
            new MailProviderResource($mailProvider->loadCount('accounts')),
            'Proveedor obtenido exitosamente'
        );
    }

    public function update(UpdateMailProviderRequest $request, MailProvider $mailProvider): JsonResponse
    {
        $mailProvider->update($request->validated());

        return $this->sendSuccess(
            new MailProviderResource($mailProvider),
            'Proveedor actualizado exitosamente'
        );
    }

    public function destroy(MailProvider $mailProvider): JsonResponse
    {
        if ($mailProvider->accounts()->exists()) {
            return $this->sendError(
                'No se puede eliminar un proveedor con cuentas asociadas',
                422
            );
        }

        $mailProvider->delete();

        return $this->sendSuccess(null, 'Proveedor eliminado exitosamente');
    }
}
