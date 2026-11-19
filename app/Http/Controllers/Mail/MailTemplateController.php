<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Mail\UpdateMailTemplateRequest;
use App\Http\Resources\Mail\MailTemplateConfigResource;
use App\Models\Mail\MailTemplateConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MailTemplateController
 *
 * Gestión de plantillas: desde qué cuenta sale cada tipo de correo y si está
 * activa. Pausar una plantilla corta ese correo en el acto, incluso el que ya
 * está encolado (la política se evalúa al enviar, en el listener de `MessageSending`).
 */
class MailTemplateController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $templates = MailTemplateConfig::query()
            ->with('mailAccount')
            ->orderBy('template_name')
            ->paginate($request->integer('per_page', 50));

        return $this->sendPaginated($templates);
    }

    public function show(MailTemplateConfig $mailTemplate): JsonResponse
    {
        return $this->sendSuccess(
            new MailTemplateConfigResource($mailTemplate->load('mailAccount')),
            'Plantilla obtenida exitosamente'
        );
    }

    public function update(UpdateMailTemplateRequest $request, MailTemplateConfig $mailTemplate): JsonResponse
    {
        $mailTemplate->update($request->validated());

        return $this->sendSuccess(
            new MailTemplateConfigResource($mailTemplate->load('mailAccount')),
            'Plantilla actualizada exitosamente'
        );
    }

    /**
     * Activar o pausar una plantilla.
     */
    public function toggle(MailTemplateConfig $mailTemplate): JsonResponse
    {
        $mailTemplate->update(['is_active' => ! $mailTemplate->is_active]);

        return $this->sendSuccess(
            new MailTemplateConfigResource($mailTemplate),
            $mailTemplate->is_active ? 'Plantilla activada' : 'Plantilla pausada'
        );
    }

    /**
     * Crear las plantillas por defecto que aún no existan.
     */
    public function seedDefaults(): JsonResponse
    {
        MailTemplateConfig::seedDefaults();

        return $this->sendSuccess(
            MailTemplateConfigResource::collection(MailTemplateConfig::all()),
            'Plantillas por defecto sincronizadas'
        );
    }
}
