<?php

namespace App\Http\Controllers\Files;

use App\Contracts\DirectUploadServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Files\StoreFileUploadRequest;
use App\Http\Resources\Files\FileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Subida directa presignada (docs/files.md §Subida directa).
 */
class FileUploadController extends Controller
{
    public function __construct(private readonly DirectUploadServiceInterface $uploads) {}

    /**
     * Crear la intención y obtener la URL PUT presignada
     *
     * POST /files/uploads
     */
    public function store(StoreFileUploadRequest $request): JsonResponse
    {
        $result = $this->uploads->create($request->user(), $request->validated());
        $intent = $result['intent'];

        return $this->sendSuccess([
            'id' => $intent->uuid,
            'status' => $intent->status,
            'name' => $intent->name,
            'size' => $intent->size,
            'mime_type' => $intent->mime_type,
            'is_public' => $intent->is_public,
            'expires_at' => $intent->expires_at->toIso8601String(),
            'upload' => $result['upload'],
        ], 'Intención de subida creada', 201);
    }

    /**
     * Completar: verificar el objeto subido y crear el archivo
     *
     * POST /files/uploads/{uuid}/complete
     */
    public function complete(Request $request, string $uuid): JsonResponse
    {
        $intent = $this->uploads->find($uuid);

        // Solo quien creó la intención (o files.manage) la completa.
        if ($intent->user_id !== $request->user()->id && ! $request->user()->can('files.manage')) {
            return $this->sendError('No tienes permiso para completar esta subida', 403);
        }

        return $this->sendSuccess(
            new FileResource($this->uploads->complete($intent)),
            'Archivo subido exitosamente'
        );
    }
}
