<?php

namespace App\Http\Controllers\Privacy;

use App\Contracts\PersonalDataExportServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\PersonalDataExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de los datos del propio usuario. `download` es una ruta firmada (el enlace del aviso) y solo sirve
 * la exportación del usuario cuya firma lo respalda.
 */
class PersonalDataExportController extends Controller
{
    public function __construct(private readonly PersonalDataExportServiceInterface $exports) {}

    public function index(Request $request): JsonResponse
    {
        return $this->sendSuccess($this->exports->list($request->user())->map(fn ($e) => $this->present($e))->all(), 'Exportaciones');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->sendSuccess($this->present($this->exports->request($request->user())), 'Exportación solicitada', 202);
    }

    public function link(Request $request, int $id): JsonResponse
    {
        return $this->sendSuccess(['url' => $this->exports->downloadUrl($request->user(), $id)], 'Enlace de descarga');
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        return $this->exports->download($id, (int) $request->query('user'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PersonalDataExport $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status,
            'size' => $export->size,
            'requested_at' => $export->created_at->toIso8601String(),
            'ready_at' => $export->ready_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
        ];
    }
}
