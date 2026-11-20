<?php

namespace App\Http\Controllers\Files;

use App\Contracts\FileServiceInterface;
use App\Exceptions\ApiException;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Files\StoreFileRequest;
use App\Http\Requests\Files\UpdateFileRequest;
use App\Http\Resources\Files\FileResource;
use App\Models\File;
use App\Models\Tenant;
use App\Services\LogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FileController
 *
 * Controlador para gestión de archivos.
 * Maneja upload, download, listado y eliminación de archivos.
 */
class FileController extends Controller
{
    /**
     * Resource para transformar respuestas
     */
    protected ?string $resource = FileResource::class;

    public function __construct(private readonly FileServiceInterface $files) {}

    /**
     * Listar archivos con paginación y filtros
     *
     * GET /api/files
     */
    public function index(Request $request): JsonResponse
    {
        $query = File::query();

        // Filtrar por usuario autenticado (solo sus archivos)
        if (! $request->user()->can('files.manage')) {
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
        $sortBy = in_array($request->input('sort_by'), ['created_at', 'name', 'size', 'type'], true)
            ? $request->input('sort_by')
            : 'created_at';
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // Paginación
        $perPage = $this->perPage($request);
        $files = $query->paginate($perPage);

        return $this->sendPaginated($files);
    }

    /**
     * Uso y límites de almacenamiento del tenant
     *
     * GET /files/usage
     */
    public function usage(): JsonResponse
    {
        return $this->sendSuccess($this->files->storageUsage(), 'Uso de almacenamiento');
    }

    /**
     * Obtener archivo específico
     *
     * GET /api/files/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $file = File::findOrFail($id);

        // Un archivo privado solo lo ve su dueño o quien tenga files.manage
        if (! $request->user()->can('view', $file)) {
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
    public function store(StoreFileRequest $request): JsonResponse
    {
        $uploadedFile = $request->file('file');
        $category = $request->input('category', 'default');
        $isPublic = $request->boolean('is_public', false);
        /** @var array<string, mixed>|null $uploadResult */
        $uploadResult = null;

        $this->files->assertVisibilityAllowed($isPublic);

        try {
            // El disco depende de la visibilidad, no de la categoría (ver config/files.php)
            $disk = $this->files->diskFor($isPublic);
            $storagePath = config("files.storage_paths.{$category}", config('files.storage_paths.default'));

            // Valida el contenido real y guarda con nombre generado
            $size = (int) $uploadedFile->getSize();
            $this->files->ensureFileSizeAllowed($size);

            // Cuota del plan: cuenta, comprueba y guarda bajo el bloqueo del tenant (sin carreras).
            $file = $this->files->withStorageQuota($size, function () use ($uploadedFile, $storagePath, $disk, $isPublic, $category, $request, &$uploadResult) {
                $uploadResult = $this->files->upload($uploadedFile, $storagePath, null, $disk, $isPublic);

                $retentionDays = config("files.retention_policies.{$category}");
                $expiresAt = $retentionDays ? now()->addDays($retentionDays) : null;

                return File::create([
                    'user_id' => $request->user()->id,
                    'name' => $this->cleanOriginalName($uploadedFile->getClientOriginalName()),
                    'filename' => $uploadResult['filename'],
                    'path' => $uploadResult['path'],
                    'url' => $uploadResult['url'],
                    'disk' => $uploadResult['disk'],
                    'mime_type' => $uploadResult['mime_type'],
                    'extension' => $uploadResult['extension'],
                    'size' => $uploadResult['size'],
                    'type' => $this->files->detectType($uploadResult['mime_type']),
                    'category' => $category,
                    'description' => $request->input('description'),
                    'is_public' => $isPublic,
                    'expires_at' => $expiresAt,
                ]);
            });
        } catch (BusinessLogicException $e) {
            return $this->sendError($e->getMessage(), 422);
        } catch (ApiException $e) {
            // Errores de dominio (cuota, tamaño del plan...): el handler global los convierte en 4xx.
            if ($uploadResult !== null) {
                $this->files->delete($uploadResult['path'], $uploadResult['disk']);
            }

            throw $e;
        } catch (\Throwable $e) {
            // Si el registro falló, no dejar el archivo huérfano en el disco
            if ($uploadResult !== null) {
                $this->files->delete($uploadResult['path'], $uploadResult['disk']);
            }

            LogService::error('Error al subir archivo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->sendError('Error al subir archivo', 500);
        }

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
    }

    /**
     * Actualizar metadatos de archivo
     *
     * PUT /api/files/{id}
     */
    public function update(UpdateFileRequest $request, string $id): JsonResponse
    {
        $file = File::findOrFail($id);

        if (! $request->user()->can('update', $file)) {
            return $this->sendError('No tienes permiso para actualizar este archivo', 403);
        }

        $data = $request->validated();
        $makePublic = array_key_exists('is_public', $data) ? (bool) $data['is_public'] : null;

        // Solo hacerlo público exige el ajuste; dejarlo (o volverlo) privado siempre se permite.
        if ($makePublic === true && ! $file->is_public) {
            $this->files->assertVisibilityAllowed(true);
        }

        try {
            $this->files->updateWithVisibility($file, $data, $makePublic);
        } catch (\Throwable $e) {
            LogService::error('Error al actualizar archivo', ['file_id' => $file->id, 'error' => $e->getMessage()]);

            return $this->sendError('Error al actualizar archivo', 500);
        }

        LogService::info('Archivo actualizado', [
            'file_id' => $file->id,
            'changes' => $request->validated(),
        ], 'activity');

        return $this->sendSuccess(
            new FileResource($file->fresh()),
            'Archivo actualizado exitosamente'
        );
    }

    /**
     * Eliminar archivo
     *
     * DELETE /api/files/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $file = File::findOrFail($id);

        if (! $request->user()->can('delete', $file)) {
            return $this->sendError('No tienes permiso para eliminar este archivo', 403);
        }

        try {
            // Primero el registro (soft delete); el archivo físico solo si eso salió bien
            $file->delete();
            $this->files->delete($file->path, $file->disk);
        } catch (\Throwable $e) {
            LogService::error('Error al eliminar archivo', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return $this->sendError('Error al eliminar archivo', 500);
        }

        LogService::info('Archivo eliminado', [
            'file_id' => $file->id,
            'filename' => $file->filename,
        ], 'activity');

        return $this->sendSuccess(null, 'Archivo eliminado exitosamente');
    }

    /**
     * Descargar archivo (dueño, files.manage, o cualquiera si es público)
     *
     * GET /files/{id}/download
     */
    public function download(Request $request, string $id): JsonResponse|StreamedResponse
    {
        $file = File::findOrFail($id);

        if (! $request->user()->can('download', $file)) {
            return $this->sendError('No tienes permiso para descargar este archivo', 403);
        }

        return $this->streamDownload($file);
    }

    /**
     * URL temporal para descargar un archivo sin cabecera Authorization.
     * Privado: URL firmada que caduca. Público: su URL estable.
     *
     * GET /files/{id}/temporary-url
     */
    public function temporaryUrl(Request $request, string $id): JsonResponse
    {
        $file = File::findOrFail($id);

        if (! $request->user()->can('download', $file)) {
            return $this->sendError('No tienes permiso para descargar este archivo', 403);
        }

        $minutes = (int) config('files.temporary_url_minutes', 15);
        $url = $file->is_public ? $file->getUrl() : $this->files->temporaryDownloadUrl($file, $minutes);

        if ($url === null) {
            return $this->sendError('No se pudo generar la URL temporal', 500);
        }

        return $this->sendSuccess([
            'url' => $url,
            'expires_at' => $file->is_public ? null : now()->addMinutes($minutes)->toIso8601String(),
        ], 'URL generada exitosamente');
    }

    /**
     * Descarga con URL firmada (middleware `signed`, sin sesión).
     *
     * GET /files/{id}/signed
     */
    public function signedDownload(Request $request, string $id): JsonResponse|StreamedResponse
    {
        $context = app(TenantContext::class);

        if (! $context->enabled()) {
            return $this->streamDownload(File::findOrFail($id));
        }

        // Multiempresa: la URL firmada es la credencial y lleva el tenant DENTRO de la firma
        // (`FileService::temporaryDownloadUrl`). Sin sesión ni X-Tenant, el contexto sale de ahí; si la
        // petición declara otro tenant (cabecera, subdominio...), se rechaza en vez de mezclar contextos.
        $tenant = ctype_digit((string) $request->query('tenant')) ? Tenant::query()->find((int) $request->query('tenant')) : null;

        if (! $tenant instanceof Tenant || ! $tenant->isActive()) {
            return $this->sendError('Enlace no válido', 403);
        }

        $declared = $request->header((string) config('multi-tenancy.resolution.header'));

        if (is_string($declared) && trim($declared) !== '' && ! in_array(trim($declared), [(string) $tenant->getKey(), (string) $tenant->slug], true)) {
            return $this->sendError('Enlace no válido para este tenant', 403);
        }

        return $context->run($tenant, fn () => $this->streamDownload(File::findOrFail($id)));
    }

    protected function streamDownload(File $file): JsonResponse|StreamedResponse
    {
        // Defensa en profundidad (multiempresa): la clave de almacenamiento lleva el tenant del
        // registro. Si no coincide, algo está mal en los datos y no se sirve el contenido.
        if (app(TenantContext::class)->enabled()
            && preg_match('#^(?:[A-Za-z0-9._-]+/)?tenants/(\d+)/#', (string) $file->path, $m) === 1
            && (int) $m[1] !== (int) $file->getAttribute('tenant_id')) {
            return $this->sendError('El archivo no existe en el almacenamiento', 404);
        }

        if (! Storage::disk($file->disk)->exists($file->path)) {
            return $this->sendError('El archivo no existe en el almacenamiento', 404);
        }

        return Storage::disk($file->disk)->download($file->path, $this->files->safeDownloadName($file), [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * Nombre original para mostrar: sin rutas ni caracteres de control.
     */
    protected function cleanOriginalName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', basename(str_replace('\\', '/', $name))) ?: 'archivo';

        return mb_substr($name, 0, 255);
    }
}
