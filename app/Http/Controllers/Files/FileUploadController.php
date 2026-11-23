<?php

namespace App\Http\Controllers\Files;

use App\Contracts\DirectUploadServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Files\PresignUploadPartsRequest;
use App\Http\Requests\Files\StoreFileUploadRequest;
use App\Http\Resources\Files\FileResource;
use App\Models\FileUploadIntent;
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
        $result = $this->uploads->create($this->authUser($request), $request->validated());
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
        if (! $this->canManage($request, $intent)) {
            return $this->sendError('No tienes permiso para completar esta subida', 403);
        }

        return $this->sendSuccess(
            new FileResource($this->uploads->complete($intent)),
            'Archivo subido exitosamente'
        );
    }

    /**
     * Crear una intención de subida por partes (archivos grandes, S3)
     *
     * POST /files/uploads/multipart
     */
    public function storeMultipart(StoreFileUploadRequest $request): JsonResponse
    {
        $result = $this->uploads->createMultipart($this->authUser($request), $request->validated());
        $intent = $result['intent'];

        return $this->sendSuccess([
            'id' => $intent->uuid,
            'status' => $intent->status,
            'name' => $intent->name,
            'size' => $intent->size,
            'mime_type' => $intent->mime_type,
            'is_public' => $intent->is_public,
            'expires_at' => $intent->expires_at->toIso8601String(),
            'part_size' => $result['part_size'],
            'parts_count' => $result['parts_count'],
        ], 'Intención de subida por partes creada', 201);
    }

    /**
     * URLs PUT prefirmadas de las partes pedidas
     *
     * POST /files/uploads/{uuid}/parts
     */
    public function presignParts(PresignUploadPartsRequest $request, string $uuid): JsonResponse
    {
        $intent = $this->uploads->find($uuid);

        if (! $this->canManage($request, $intent)) {
            return $this->sendError('No tienes permiso para esta subida', 403);
        }

        /** @var list<int> $numbers */
        $numbers = array_map('intval', (array) $request->validated('part_numbers'));

        return $this->sendSuccess($this->uploads->presignParts($intent, $numbers));
    }

    /**
     * Partes ya subidas (para reanudar)
     *
     * GET /files/uploads/{uuid}/parts
     */
    public function parts(Request $request, string $uuid): JsonResponse
    {
        $intent = $this->uploads->find($uuid);

        if (! $this->canManage($request, $intent)) {
            return $this->sendError('No tienes permiso para esta subida', 403);
        }

        return $this->sendSuccess($this->uploads->listParts($intent));
    }

    /**
     * Abortar la subida y liberar la cuota reservada
     *
     * DELETE /files/uploads/{uuid}
     */
    public function abort(Request $request, string $uuid): JsonResponse
    {
        $intent = $this->uploads->find($uuid);

        if (! $this->canManage($request, $intent)) {
            return $this->sendError('No tienes permiso para esta subida', 403);
        }

        $this->uploads->abort($intent);

        return $this->sendSuccess(null, 'Subida abortada');
    }

    /** Solo quien creó la intención (o `files.manage`). */
    private function canManage(Request $request, FileUploadIntent $intent): bool
    {
        return $intent->user_id === $this->authUser($request)->id || $this->authUser($request)->can('files.manage');
    }
}
