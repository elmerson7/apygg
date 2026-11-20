<?php

namespace App\Http\Controllers\Audit;

use App\Contracts\AuditServiceInterface;
use App\Http\Controllers\Concerns\StreamsAuditCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAuditRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Auditoría de toda la plataforma (`audit.read`). `tenant_id` filtra por empresa.
 */
class AuditController extends Controller
{
    use StreamsAuditCsv;

    private const FILTERS = ['tenant_id', 'from', 'to', 'actor_type', 'actor_id', 'event', 'model', 'model_id'];

    public function __construct(private readonly AuditServiceInterface $audit) {}

    public function index(ListAuditRequest $request): JsonResponse
    {
        return $this->sendPaginated($this->audit->paginateForPlatform(
            $request->safe()->only(self::FILTERS),
            $this->perPage($request, 25),
        ));
    }

    public function export(ListAuditRequest $request): StreamedResponse
    {
        return $this->csvResponse(
            $this->audit->exportForPlatform($request->safe()->only(self::FILTERS)),
            'auditoria-'.now()->format('Ymd-His').'.csv',
        );
    }
}
