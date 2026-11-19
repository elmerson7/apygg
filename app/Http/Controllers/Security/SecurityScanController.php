<?php

namespace App\Http\Controllers\Security;

use App\Contracts\SecurityAuditServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\SecurityScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SecurityScanController
 *
 * Lanzar y consultar escaneos de seguridad de la aplicación.
 */
class SecurityScanController extends Controller
{
    public function __construct(private readonly SecurityAuditServiceInterface $audit) {}

    public function index(Request $request): JsonResponse
    {
        $scans = SecurityScan::query()
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 15));

        return $this->sendPaginated($scans);
    }

    public function show(SecurityScan $securityScan): JsonResponse
    {
        return $this->sendSuccess(
            $securityScan->load(['creator:id,name', 'stoppedBy:id,name']),
            'Escaneo obtenido exitosamente'
        );
    }

    /**
     * Lanzar un escaneo. Solo uno a la vez: dos escaneos simultáneos
     * analizarían la misma ventana y duplicarían el gasto de IA.
     */
    public function store(Request $request): JsonResponse
    {
        if ($active = $this->audit->activeScan()) {
            return $this->sendError(
                'Ya hay un escaneo en curso',
                409,
                ['scan_id' => $active->id, 'status' => $active->status]
            );
        }

        $scan = $this->audit->start($request->user());

        return $this->sendSuccess($scan, 'Escaneo iniciado exitosamente', 201);
    }

    public function stop(Request $request, SecurityScan $securityScan): JsonResponse
    {
        $this->audit->stop($securityScan, $request->user());

        return $this->sendSuccess($securityScan->fresh(), 'Escaneo detenido exitosamente');
    }

    /**
     * Hallazgos locales sin lanzar un escaneo ni llamar al LLM.
     * Útil para una comprobación rápida y para un health check de seguridad.
     */
    public function preview(): JsonResponse
    {
        $findings = $this->audit->collectFindings();

        return $this->sendSuccess([
            'severity' => $this->audit->localSeverity($findings),
            'findings' => $findings,
        ], 'Hallazgos obtenidos exitosamente');
    }
}
