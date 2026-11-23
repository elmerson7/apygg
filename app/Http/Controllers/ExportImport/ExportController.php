<?php

namespace App\Http\Controllers\ExportImport;

use App\Contracts\ExportServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportImport\StoreExportRequest;
use App\Http\Resources\ExportImport\DataExportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportaciones del usuario en el tenant activo. El exportador lo fija la ruta (`defaults`).
 */
class ExportController extends Controller
{
    protected ?string $resource = DataExportResource::class;

    public function __construct(private readonly ExportServiceInterface $exports) {}

    public function store(StoreExportRequest $request): JsonResponse
    {
        $export = $this->exports->request(
            $this->authUser($request),
            (string) $request->route('exporter'),
            (string) $request->validated('format'),
            (array) $request->validated('filters', []),
        );

        return $this->sendSuccess(new DataExportResource($export), 'Exportación encolada', 202);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->sendPaginated($this->exports->paginateFor($this->authUser($request), $this->perPage($request)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->sendSuccess(new DataExportResource($this->exports->findFor($this->authUser($request), $id)));
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        return $this->exports->download($this->exports->findFor($this->authUser($request), $id));
    }
}
