<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Http\Requests\Files\StoreFileRequest;
use App\Http\Requests\Files\UpdateFileRequest;
use App\Http\Resources\Files\FileResource;
use App\Models\File;
use App\Services\FileService;
use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * FileController
 *
 * Controlador para gestión de archivos.
 * Maneja upload, download, listado y eliminación de archivos.
 */
class FileController extends Controller
{
    /**
     * Modelo asociado al controlador
     */
    protected ?string $model = File::class;

    /**
     * Resource para transformar respuestas
     */
    protected ?string $resource = FileResource::class;

    /**
     * Listar archivos con paginación y filtros
     *
     * GET /api/files
     */
    public function index(Request $request): JsonResponse
    {
        $query = File::query();

        // Filtrar por usuario autenticado (solo sus archivos)
        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        } elseif ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // Filtros
        if ($request->has('type')) {
            $query->ofType($request->input('type'));
        }

        if ($request->has('category')) {
            $query->ofCategory($request->input('category'));
        }

        if ($request->has('is_public')) {
            $query->where('is_public', $request->boolean('is_public'));
        }

        // Ordenamiento
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Paginación
        $perPage = min($request->input('per_page', 15), 100);
        $files = $query->paginate($perPage);

        // Configurar resource para transformación automática
        $this->resource = FileResource::class;

        return $this->sendPaginated($files);
    }

    /**
     * Obtener archivo específico
     *
     * GET /api/files/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $file = File::findOrFail($id);

        // Verificar permisos: solo el dueño o admin puede ver archivos privados
        if (! $file->is_public && $file->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            return $this->sendError('No tienes permiso para ver este archivo', 403);
        }

        return $this->sendSuccess(
            new FileResource($file),
            'Archivo obtenido exitosamente'
        );
    }

    /**
     * Subir nuevo archivo
     *
     * POST /api/files
     */
    public function store(Request $request): JsonResponse
    {
        // Validar usando StoreFileRequest (compatible con método padre)
        $formRequest = StoreFileRequest::createFrom($request);
        $formRequest->setContainer(app());
        $formRequest->setRedirector(app('redirect'));
        $formRequest->validateResolved();

        try {
            $uploadedFile = $formRequest->file('file');
            $category = $formRequest->input('category', 'default');
            $description = $formRequest->input('description');
            $isPublic = $formRequest->boolean('is_public', false);

            // Determinar tipo de archivo
            $type = $this->determineFileType($uploadedFile->getMimeType());

            // Obtener configuración según categoría
            $storagePath = config("files.storage_paths.{$category}", config('files.storage_paths.default'));
            $disk = config("files.disk_by_type.{$category}", config('files.disk_by_type.default'));

            // Subir archivo usando FileService
            $uploadResult = FileService::upload(
                $uploadedFile,
                $storagePath,
                null, // Generar nombre automático
                $disk
            );

            // Calcular fecha de expiración según política de retención
            $retentionDays = config("files.retention_policies.{$category}");
            $expiresAt = $retentionDays ? now()->addDays($retentionDays) : null;

            // Crear registro en base de datos
            $file = File::create([
                'user_id' => $formRequest->user()->id,
                'name' => $uploadedFile->getClientOriginalName(),
                'filename' => $uploadResult['filename'],
                'path' => $uploadResult['path'],
                'url' => $uploadResult['url'],
                'disk' => $uploadResult['disk'],
                'mime_type' => $uploadResult['mime_type'],
                'extension' => $uploadedFile->getClientOriginalExtension(),
                'size' => $uploadResult['size'],
                'type' => $type,
                'category' => $category,
                'description' => $description,
                'is_public' => $isPublic,
                'expires_at' => $expiresAt,
            ]);

            LogService::info('Archivo subido exitosamente', [
                'file_id' => $file->id,
                'filename' => $file->filename,
                'size' => $file->size,
                'type' => $file->type,
            ], 'activity');

            return $this->sendSuccess(
                new FileResource($file),
                'Archivo subido exitosamente',
                201
            );
        } catch (\Exception $e) {
            LogService::error('Error al subir archivo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->sendError('Error al subir archivo: '.$e->getMessage(), 500);
        }
    }

    /**
     * Actualizar metadatos de archivo
     *
     * PUT /api/files/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        // Validar usando UpdateFileRequest (compatible con método padre)
        $formRequest = UpdateFileRequest::createFrom($request);
        $formRequest->setContainer(app());
        $formRequest->setRedirector(app('redirect'));
        $formRequest->validateResolved();

        $file = File::findOrFail($id);

        // Verificar permisos: solo el dueño o admin puede actualizar
        if ($file->user_id !== $formRequest->user()->id && ! $formRequest->user()->isAdmin()) {
            return $this->sendError('No tienes permiso para actualizar este archivo', 403);
        }

        $file->update($formRequest->validated());

        LogService::info('Archivo actualizado', [
            'file_id' => $file->id,
            'changes' => $formRequest->validated(),
        ], 'activity');

        return $this->sendSuccess(
            new FileResource($file),
            'Archivo actualizado exitosamente'
        );
    }

    /**
     * Eliminar archivo
     *
     * DELETE /api/files/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $file = File::findOrFail($id);
        $request = request();

        // Verificar permisos: solo el dueño o admin puede eliminar
        if ($file->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            return $this->sendError('No tienes permiso para eliminar este archivo', 403);
        }

        try {
            // Eliminar archivo físico
            FileService::delete($file->path, $file->disk);

            // Eliminar registro de base de datos (soft delete)
            $file->delete();

            LogService::info('Archivo eliminado', [
                'file_id' => $file->id,
                'filename' => $file->filename,
            ], 'activity');

            return $this->sendSuccess(null, 'Archivo eliminado exitosamente');
        } catch (\Exception $e) {
            LogService::error('Error al eliminar archivo', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return $this->sendError('Error al eliminar archivo: '.$e->getMessage(), 500);
        }
    }

    /**
     * Descargar archivo
     *
     * GET /api/files/{id}/download
     */
    public function download(Request $request, string $id)
    {
        $file = File::findOrFail($id);

        // Verificar permisos: solo el dueño o admin puede descargar archivos privados
        if (! $file->is_public && $file->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            return $this->sendError('No tienes permiso para descargar este archivo', 403);
        }

        // Verificar que el archivo existe físicamente
        if (! Storage::disk($file->disk)->exists($file->path)) {
            return $this->sendError('El archivo no existe en el almacenamiento', 404);
        }

        return Storage::disk($file->disk)->download($file->path, $file->name);
    }

    /**
     * Determinar tipo de archivo basado en MIME type
     */
    protected function determineFileType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        if (in_array($mimeType, ['application/zip', 'application/x-rar-compressed', 'application/x-tar', 'application/gzip'])) {
            return 'archive';
        }
        if (str_starts_with($mimeType, 'application/') || str_starts_with($mimeType, 'text/')) {
            return 'document';
        }

        return 'other';
    }
}
