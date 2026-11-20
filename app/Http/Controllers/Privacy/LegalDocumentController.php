<?php

namespace App\Http\Controllers\Privacy;

use App\Contracts\ConsentServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\ListLegalDocumentsRequest;
use App\Http\Requests\Privacy\PublishLegalDocumentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lectura pública de la versión vigente (`/legal`) y administración de versiones (`/admin/legal-documents`).
 */
class LegalDocumentController extends Controller
{
    public function __construct(private readonly ConsentServiceInterface $consents) {}

    public function current(Request $request): JsonResponse
    {
        return $this->sendSuccess($this->consents->current($request->query('locale') ? (string) $request->query('locale') : null), 'Documentos legales vigentes');
    }

    public function show(Request $request, string $key): JsonResponse
    {
        return $this->sendSuccess($this->consents->currentFor($key, $request->query('locale') ? (string) $request->query('locale') : null), 'Documento legal');
    }

    public function index(ListLegalDocumentsRequest $request): JsonResponse
    {
        return $this->sendPaginated($this->consents->paginate($request->safe()->only(['key', 'locale']), $this->perPage($request, 25)));
    }

    public function store(PublishLegalDocumentRequest $request): JsonResponse
    {
        /** @var array{key: string, version: string, locale: string, title: string, body: string, effective_at?: string|null} $data */
        $data = $request->validated();

        return $this->sendSuccess($this->consents->publish($data, $request->user()), 'Documento publicado', 201);
    }
}
