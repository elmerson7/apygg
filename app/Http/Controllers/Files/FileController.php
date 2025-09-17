<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Http\Requests\Files\GenerateUploadUrlRequest;
use App\Http\Requests\Files\ConfirmUploadRequest;
use App\Http\Requests\Files\DirectUploadRequest;
use App\Http\Resources\Files\FileResource;
use App\Http\Resources\Files\UploadUrlResource;
use App\Http\Resources\Files\FileCollection;
use App\Services\FileService;
use App\Repositories\FileRepository;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileController extends Controller
{
    public function __construct(
        private FileService $fileService,
        private FileRepository $fileRepository
    ) {}

    /**
     * Generar URL presigned para upload
     */
    public function generateUploadUrl(GenerateUploadUrlRequest $request): JsonResponse
    {
        $validated = $request->validated();
        
        $uploadData = $this->fileService->generateUploadUrl(
            type: $validated['type'],
            originalName: $validated['original_name'],
            mimeType: $validated['mime_type'],
            size: $validated['size'],
            user: $request->user(),
            meta: $validated['meta'] ?? []
        );

        return response()->apiJson([
            'upload' => new UploadUrlResource($uploadData),
        ]);
    }

    /**
     * Confirmar que el upload fue exitoso
     */
    public function confirmUpload(ConfirmUploadRequest $request): JsonResponse
    {
        $validated = $request->validated();
        
        $file = $this->fileService->confirmUpload(
            fileId: $validated['file_id'],
            checksum: $validated['checksum'] ?? null
        );

        return response()->apiJson([
            'file' => new FileResource($file),
        ]);
    }

    /**
     * Upload directo de archivo (sin presigned URL)
     */
    public function uploadDirect(DirectUploadRequest $request): JsonResponse
    {
        $validated = $request->validated();
        
        $file = $this->fileService->uploadDirect(
            uploadedFile: $request->file('file'),
            type: $validated['type'],
            user: $request->user(),
            meta: $validated['meta'] ?? []
        );

        return response()->apiJson([
            'file' => new FileResource($file),
        ], 201);
    }

    /**
     * Generar URL de descarga temporal
     */
    public function generateDownloadUrl(Request $request, string $fileId): JsonResponse
    {
        $file = $this->fileRepository->getUserFile($request->user(), $fileId);
        
        if (!$file) {
            return response()->apiJson([
                'error' => 'File not found or access denied',
            ], 404);
        }

        if ($file->status !== File::STATUS_VERIFIED) {
            return response()->apiJson([
                'error' => 'File is not available for download',
                'status' => $file->status,
            ], 422);
        }

        $expiration = $request->integer('expiration', 3600);
        $downloadUrl = $this->fileService->generateDownloadUrl($file, $expiration);

        return response()->apiJson([
            'download_url' => $downloadUrl,
            'expires_at' => now()->addSeconds($expiration)->toISOString(),
        ]);
    }

    /**
     * Obtener información de un archivo
     */
    public function show(Request $request, string $fileId): JsonResponse
    {
        $file = $this->fileRepository->getUserFile($request->user(), $fileId);
        
        if (!$file) {
            return response()->apiJson([
                'error' => 'File not found or access denied',
            ], 404);
        }

        return response()->apiJson([
            'file' => new FileResource($file),
        ]);
    }

    /**
     * Listar archivos del usuario
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'nullable|string|in:avatar,document,general,temp',
            'status' => 'nullable|string|in:uploading,scanning,verified,infected,failed',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $files = $this->fileRepository->getUserFiles(
            user: $request->user(),
            type: $validated['type'] ?? null,
            status: $validated['status'] ?? null,
            perPage: $validated['per_page'] ?? 15
        );

        return response()->apiJson([
            'files' => new FileCollection($files),
        ]);
    }

    /**
     * Eliminar archivo
     */
    public function destroy(Request $request, string $fileId): JsonResponse
    {
        $file = $this->fileRepository->getUserFile($request->user(), $fileId);
        
        if (!$file) {
            return response()->apiJson([
                'error' => 'File not found or access denied',
            ], 404);
        }

        $deleted = $this->fileService->deleteFile($file);

        if (!$deleted) {
            return response()->apiJson([
                'error' => 'Failed to delete file',
            ], 500);
        }

        return response()->apiJson([
            'message' => 'File deleted successfully',
        ]);
    }

    /**
     * Buscar archivos del usuario
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $files = $this->fileRepository->searchByName(
            query: $validated['q'],
            user: $request->user(),
            perPage: $validated['per_page'] ?? 15
        );

        return response()->apiJson([
            'files' => new FileCollection($files),
            'query' => $validated['q'],
        ]);
    }

    /**
     * Obtener estadísticas de archivos del usuario
     */
    public function stats(Request $request): JsonResponse
    {
        $stats = $this->fileRepository->getStats($request->user());

        return response()->apiJson([
            'stats' => $stats,
        ]);
    }

    /**
     * Obtener archivos recientes del usuario
     */
    public function recent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $files = $this->fileRepository->getRecent(
            user: $request->user(),
            limit: $validated['limit'] ?? 10
        );

        return response()->apiJson([
            'files' => FileResource::collection($files),
        ]);
    }
}
