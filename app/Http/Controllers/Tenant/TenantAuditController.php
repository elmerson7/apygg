<?php

namespace App\Http\Controllers\Tenant;

use App\Contracts\AuditServiceInterface;
use App\Http\Controllers\Concerns\StreamsAuditCsv;
use App\Http\Controllers\Concerns\UsesActiveTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAuditRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Auditoría del tenant activo (middleware `tenant` + `tenant-audit.read`; el rol del actor en el
 * tenant se vuelve a comprobar en el servicio). El tenant sale del contexto, nunca de la petición.
 */
class TenantAuditController extends Controller
{
    use StreamsAuditCsv, UsesActiveTenant;

    private const FILTERS = ['from', 'to', 'actor_type', 'actor_id', 'event', 'model', 'model_id'];

    public function __construct(private readonly AuditServiceInterface $audit) {}

    public function index(ListAuditRequest $request): JsonResponse
    {
        return $this->sendPaginated($this->audit->paginateForTenant(
            $this->activeTenant($request),
            $request->user(),
            $request->safe()->only(self::FILTERS),
            $this->perPage($request, 25),
        ));
    }

    public function export(ListAuditRequest $request): StreamedResponse
    {
        $tenant = $this->activeTenant($request);
        $rows = $this->audit->exportForTenant($tenant, $request->user(), $request->safe()->only(self::FILTERS));

        return $this->csvResponse($rows, 'auditoria-'.$tenant->slug.'-'.now()->format('Ymd-His').'.csv');
    }
}
