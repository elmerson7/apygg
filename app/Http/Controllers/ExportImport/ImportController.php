<?php

namespace App\Http\Controllers\ExportImport;

use App\Contracts\ImportServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportImport\StoreImportRequest;
use App\Http\Resources\ExportImport\DataImportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importaciones del usuario en el tenant activo: subir -> vista previa -> confirmar. El importador lo fija la ruta.
 */
class ImportController extends Controller
{
    protected ?string $resource = DataImportResource::class;

    public function __construct(private readonly ImportServiceInterface $imports) {}

    public function store(StoreImportRequest $request): JsonResponse
    {
        $import = $this->imports->upload($this->authUser($request), (string) $request->route('importer'), $request->file('file'));

        return $this->sendSuccess(new DataImportResource($import), 'Archivo recibido; validando', 202);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->sendPaginated($this->imports->paginateFor($this->authUser($request), $this->perPage($request)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->sendSuccess(new DataImportResource($this->imports->findFor($this->authUser($request), $id)));
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        return $this->sendSuccess(new DataImportResource($this->imports->confirm($this->authUser($request), $id)), 'Importación confirmada', 202);
    }

    public function errors(Request $request, int $id): StreamedResponse
    {
        return $this->imports->errorsReport($this->imports->findFor($this->authUser($request), $id));
    }
}
