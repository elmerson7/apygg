<?php

namespace App\Services;

use App\Contracts\SecurityAuditServiceInterface;
use App\Jobs\RunSecurityScanJob;
use App\Models\ApiKey;
use App\Models\Logs\ActivityLog;
use App\Models\Logs\ApiLog;
use App\Models\Logs\SecurityLog;
use App\Models\SecurityScan;
use App\Models\User;
use App\Services\LLM\LlmGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SecurityAuditService
 *
 * Escaneo de seguridad a nivel de aplicación.
 *
 * Flujo:
 *   1. Recolección local de hallazgos (configuración, logs de seguridad, API,
 *      actividad, usuarios recientes, claves de API).
 *   2. Anonimización: los datos sensibles se hashean con una sal por escaneo.
 *   3. Análisis con LLM sobre los hallazgos YA ANONIMIZADOS.
 *
 * El orden importa: el LLM ve conteos, rutas y hashes opacos, nunca IPs ni
 * correos reales. Los datos de los usuarios no salen de la aplicación aunque el
 * modelo esté alojado por un tercero.
 *
 * Deliberadamente NO escanea el host (procesos, puertos, ficheros del sistema).
 * Eso exige ejecutar comandos en la máquina, y un boilerplate no debe traer de
 * serie una ruta de ejecución de comandos dirigida por un LLM.
 */
class SecurityAuditService implements SecurityAuditServiceInterface
{
    /** Ventana de análisis, en horas. */
    public const WINDOW_HOURS = 24;

    /**
     * Orden de severidades, de menor a mayor.
     *
     * @var array<string, int>
     */
    private const SEVERITY_ORDER = ['ok' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /**
     * Claves cuyo valor se hashea antes de salir hacia el LLM.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = ['ip_address', 'user_id', 'user_agent', 'email', 'trace_id'];

    /**
     * Escaneo en curso, si lo hay.
     */
    public function activeScan(): ?SecurityScan
    {
        return SecurityScan::query()->active()->latest()->first();
    }

    /**
     * Crear el escaneo y despachar el job.
     */
    public function start(User $user): SecurityScan
    {
        $scan = SecurityScan::create([
            'created_by' => $user->id,
            'status' => SecurityScan::STATUS_PENDING,
            'salt' => SecurityScan::generateSalt(),
        ]);

        RunSecurityScanJob::dispatch($scan->id);

        return $scan;
    }

    /**
     * Marcar un escaneo como detenido.
     */
    public function stop(SecurityScan $scan, User $user): void
    {
        if (! $scan->isActive()) {
            return;
        }

        $scan->update([
            'status' => SecurityScan::STATUS_STOPPED,
            'stopped_by' => $user->id,
            'finished_at' => now(),
        ]);
    }

    /**
     * Ejecutar el escaneo completo. Lo llama el job.
     */
    public function run(SecurityScan $scan): void
    {
        $scan->update([
            'status' => SecurityScan::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $findings = $this->collectFindings();
            $anonymized = $this->anonymize($findings, $scan);

            $scan->update(['findings' => $anonymized]);

            $analysis = $this->analyzeWithLlm($anonymized, $scan);

            $scan->update([
                'status' => SecurityScan::STATUS_COMPLETED,
                'analysis' => $analysis,
                'llm_model' => $analysis['model'] ?? null,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('SecurityAuditService: el escaneo falló', [
                'scan_id' => $scan->id,
                'error' => $e->getMessage(),
            ]);

            $scan->update([
                'status' => SecurityScan::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Recolección
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collectFindings(): array
    {
        return array_values(array_filter([
            $this->checkEnvironment(),
            $this->checkSecurityLogs(),
            $this->checkApiErrors(),
            $this->checkActivityLogs(),
            $this->checkRecentUsers(),
            $this->checkApiKeys(),
        ]));
    }

    /**
     * Configuración del entorno: los fallos más caros suelen estar aquí.
     *
     * @return array<string, mixed>
     */
    private function checkEnvironment(): array
    {
        $issues = [];
        $severity = 'ok';

        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $jwtSecret = (string) config('jwt.secret');

        if ($env !== 'production') {
            $issues[] = "APP_ENV está en '{$env}' (no es producción)";
            $severity = $this->worstSeverity($severity, 'medium');
        }

        if ($env === 'production' && $debug) {
            $issues[] = 'APP_DEBUG activo en producción: expone stack traces y configuración';
            $severity = $this->worstSeverity($severity, 'critical');
        }

        if (strlen($jwtSecret) < 64) {
            $issues[] = 'JWT_SECRET es débil (menos de 64 caracteres)';
            $severity = $this->worstSeverity($severity, 'high');
        }

        if ($env === 'production' && ! str_starts_with((string) config('app.url'), 'https://')) {
            $issues[] = 'APP_URL no usa HTTPS en producción';
            $severity = $this->worstSeverity($severity, 'high');
        }

        if ($env === 'production' && config('telescope.enabled')) {
            $issues[] = 'Telescope está habilitado en producción (expone requests, consultas y payloads)';
            $severity = $this->worstSeverity($severity, 'high');
        }

        if ($issues === []) {
            $issues[] = 'Configuración del entorno correcta';
        }

        return $this->finding('environment', 'Configuración del entorno', $severity, $issues);
    }

    /**
     * Logins fallidos, actividad sospechosa y bloqueos.
     *
     * @return array<string, mixed>
     */
    private function checkSecurityLogs(): array
    {
        $since = now()->subHours(self::WINDOW_HOURS);
        $issues = [];
        $evidence = [];
        $severity = 'ok';

        $loginFailures = SecurityLog::loginFailures()->where('created_at', '>=', $since)->count();

        if ($loginFailures > 0) {
            $issues[] = "{$loginFailures} intentos de login fallidos";
            $severity = $this->worstSeverity($severity, $loginFailures >= 10 ? 'high' : 'medium');

            $evidence = SecurityLog::loginFailures()
                ->where('created_at', '>=', $since)
                ->select('ip_address', DB::raw('COUNT(*) as total'))
                ->groupBy('ip_address')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'ip_address' => (string) $row->ip_address,
                    'count' => (int) $row->getAttribute('total'),
                ])
                ->all();
        } else {
            $issues[] = 'Sin intentos de login fallidos';
        }

        $suspicious = SecurityLog::suspiciousActivity()->where('created_at', '>=', $since)->count();

        if ($suspicious > 0) {
            $issues[] = "{$suspicious} eventos de actividad sospechosa";
            $severity = $this->worstSeverity($severity, 'high');
        }

        $denied = SecurityLog::byEventType(SecurityLog::EVENT_PERMISSION_DENIED)
            ->where('created_at', '>=', $since)->count();

        if ($denied > 0) {
            $issues[] = "{$denied} accesos denegados por permisos";
            $severity = $this->worstSeverity($severity, $denied >= 20 ? 'medium' : 'low');
        }

        $locked = SecurityLog::byEventType(SecurityLog::EVENT_ACCOUNT_LOCKED)
            ->where('created_at', '>=', $since)->count();

        if ($locked > 0) {
            $issues[] = "{$locked} cuentas bloqueadas";
            $severity = $this->worstSeverity($severity, 'medium');
        }

        return $this->finding(
            'security_logs',
            'Logs de seguridad (últimas '.self::WINDOW_HOURS.'h)',
            $severity,
            $issues,
            $evidence
        );
    }

    /**
     * Errores 5xx: pueden ser un fallo propio o el rastro de alguien probando
     * entradas hasta romper algo.
     *
     * @return array<string, mixed>
     */
    private function checkApiErrors(): array
    {
        $since = now()->subHours(self::WINDOW_HOURS);
        $label = 'Errores HTTP 5xx (últimas '.self::WINDOW_HOURS.'h)';

        $total = ApiLog::where('response_status', '>=', 500)
            ->where('created_at', '>=', $since)
            ->count();

        if ($total === 0) {
            return $this->finding('api_errors', $label, 'ok', ['Sin errores 5xx']);
        }

        $byPath = ApiLog::where('response_status', '>=', 500)
            ->where('created_at', '>=', $since)
            ->select('request_path', DB::raw('COUNT(*) as total'))
            ->groupBy('request_path')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'path' => (string) $row->request_path,
                'count' => (int) $row->getAttribute('total'),
            ])
            ->all();

        return $this->finding(
            'api_errors',
            $label,
            $total >= 20 ? 'high' : 'medium',
            ["{$total} errores 5xx (fallo de la aplicación o intento de explotación)"],
            $byPath
        );
    }

    /**
     * Borrados masivos: pueden ser limpieza de evidencia.
     *
     * @return array<string, mixed>
     */
    private function checkActivityLogs(): array
    {
        $since = now()->subHours(self::WINDOW_HOURS);
        $label = 'Actividad destructiva (últimas '.self::WINDOW_HOURS.'h)';

        $deleted = ActivityLog::where('action', ActivityLog::ACTION_DELETED)
            ->where('created_at', '>=', $since)
            ->count();

        if ($deleted === 0) {
            return $this->finding('activity_logs', $label, 'ok', ['Sin borrados registrados']);
        }

        $byModel = ActivityLog::where('action', ActivityLog::ACTION_DELETED)
            ->where('created_at', '>=', $since)
            ->select('model_type', DB::raw('COUNT(*) as total'))
            ->groupBy('model_type')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'model_type' => class_basename((string) $row->model_type),
                'count' => (int) $row->getAttribute('total'),
            ])
            ->all();

        return $this->finding(
            'activity_logs',
            $label,
            $deleted >= 50 ? 'high' : 'low',
            ["{$deleted} registros eliminados"],
            $byModel
        );
    }

    /**
     * Usuarios nuevos, y sobre todo administradores nuevos.
     *
     * @return array<string, mixed>
     */
    private function checkRecentUsers(): array
    {
        $since = now()->subHours(self::WINDOW_HOURS);
        $label = 'Usuarios nuevos (últimas '.self::WINDOW_HOURS.'h)';

        $total = User::where('created_at', '>=', $since)->count();

        if ($total === 0) {
            return $this->finding('recent_users', $label, 'ok', ['Sin usuarios creados recientemente']);
        }

        $admins = User::where('users.created_at', '>=', $since)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->count();

        $issues = ["{$total} usuarios creados"];
        $severity = 'low';

        if ($admins > 0) {
            // Un administrador creado fuera del alta habitual es la señal más
            // clara de una intrusión con éxito.
            $issues[] = "{$admins} con rol administrador — revisar de inmediato";
            $severity = 'critical';
        }

        return $this->finding('recent_users', $label, $severity, $issues);
    }

    /**
     * Claves de API: las que no caducan nunca son un riesgo permanente.
     *
     * @return array<string, mixed>
     */
    private function checkApiKeys(): array
    {
        $issues = [];
        $severity = 'ok';

        // Las claves revocadas se borran en blando, así que el modelo (con
        // SoftDeletes) ya excluye las revocadas por defecto.
        $total = ApiKey::query()->count();
        $neverExpire = ApiKey::query()->whereNull('expires_at')->count();
        $expired = ApiKey::query()->whereNotNull('expires_at')->where('expires_at', '<', now())->count();

        $issues[] = "{$total} claves de API vigentes";

        if ($neverExpire > 0) {
            // Una clave sin caducidad es un riesgo permanente: si se filtra,
            // sigue siendo válida hasta que alguien se acuerde de revocarla.
            $issues[] = "{$neverExpire} sin fecha de caducidad";
            $severity = $this->worstSeverity($severity, 'medium');
        }

        if ($expired > 0) {
            $issues[] = "{$expired} caducadas pero no revocadas";
            $severity = $this->worstSeverity($severity, 'low');
        }

        return $this->finding('api_keys', 'Claves de API', $severity, $issues);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Anonimización
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Hashear los datos sensibles con la sal del escaneo.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    public function anonymize(array $findings, SecurityScan $scan): array
    {
        return array_map(function (array $finding) use ($scan): array {
            $finding['evidence'] = array_map(
                fn (array $evidence) => $this->anonymizeEvidence($evidence, $scan),
                $finding['evidence'] ?? []
            );

            return $finding;
        }, $findings);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function anonymizeEvidence(array $evidence, SecurityScan $scan): array
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            if (isset($evidence[$key]) && is_string($evidence[$key]) && $evidence[$key] !== '') {
                $evidence[$key] = $this->hashValue($evidence[$key], $scan);
            }
        }

        return $evidence;
    }

    /**
     * El prefijo `h_` deja claro en el prompt que es un identificador opaco y
     * no un valor real, para que el modelo no intente interpretarlo.
     */
    private function hashValue(string $value, SecurityScan $scan): string
    {
        return 'h_'.hash('sha256', $scan->salt.$value);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Análisis
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Analizar con el LLM los hallazgos ya anonimizados.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    public function analyzeWithLlm(array $findings, SecurityScan $scan): array
    {
        $gateway = app(LlmGateway::class)
            ->useCase('security.audit')
            ->module('security')
            ->withMeta(['scan_id' => $scan->id]);

        $analysis = $gateway->structured([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->analysisPrompt($scan, $findings)],
        ]);

        if ($analysis === null) {
            // Sin IA el escaneo sigue siendo útil: los hallazgos locales ya
            // están recolectados y la severidad se puede calcular sin modelo.
            return [
                'overall_severity' => $this->localSeverity($findings),
                'summary' => 'Análisis automático no disponible. Los hallazgos locales se recolectaron correctamente.',
                'threats_found' => [],
                'recommendations' => [],
                'llm_available' => false,
            ];
        }

        return [
            ...$this->normalizeAnalysis($analysis),
            'llm_available' => true,
        ];
    }

    private function systemPrompt(): string
    {
        return 'Eres un experto en seguridad de aplicaciones web (Laravel, PostgreSQL, Docker). '
            .'Los datos que recibes YA ESTÁN ANONIMIZADOS (hashes SHA-256 con sal, prefijados con "h_"): '
            .'nunca intentes deshashearlos y trátalos como identificadores opacos. '
            .'Analiza los hallazgos buscando señales de intrusión, fuga de datos, anomalías o malas '
            .'configuraciones. Responde SIEMPRE en JSON válido, sin texto adicional.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function analysisPrompt(SecurityScan $scan, array $findings): string
    {
        $json = json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
        Escaneo de seguridad de la aplicación (ID: {$scan->id}).
        Los identificadores están hasheados con sal: los conteos y las rutas son reales,
        los valores que empiezan por "h_" son opacos.

        HALLAZGOS:
        {$json}

        INSTRUCCIONES:
        1. Evalúa la severidad global.
        2. Identifica señales de intrusión, fuga de datos, anomalías o malas configuraciones.
        3. Para cada amenaza alta o crítica, explica por qué es relevante.
        4. Da recomendaciones concretas y priorizadas.

        Responde SOLO con este JSON:
        {
            "overall_severity": "ok|low|medium|high|critical",
            "summary": "Resumen ejecutivo",
            "threats_found": [
                {
                    "finding": "hallazgo relacionado",
                    "threat": "riesgo identificado",
                    "severity": "low|medium|high|critical",
                    "analysis": "explicación técnica"
                }
            ],
            "recommendations": ["recomendación concreta"]
        }
        PROMPT;
    }

    /**
     * El modelo puede devolver claves de más, de menos o con otro tipo. Se
     * normaliza para que el consumidor tenga siempre la misma forma.
     *
     * @param  array<mixed>  $analysis
     * @return array<string, mixed>
     */
    private function normalizeAnalysis(array $analysis): array
    {
        $severity = is_string($analysis['overall_severity'] ?? null)
            ? strtolower($analysis['overall_severity'])
            : 'unknown';

        return [
            'overall_severity' => array_key_exists($severity, self::SEVERITY_ORDER) ? $severity : 'unknown',
            'summary' => is_string($analysis['summary'] ?? null) ? $analysis['summary'] : '',
            'threats_found' => is_array($analysis['threats_found'] ?? null) ? array_values($analysis['threats_found']) : [],
            'recommendations' => is_array($analysis['recommendations'] ?? null) ? array_values($analysis['recommendations']) : [],
        ];
    }

    /**
     * Severidad global calculada a partir de los hallazgos, sin IA.
     *
     * @param  array<int, array<string, mixed>>  $findings
     */
    public function localSeverity(array $findings): string
    {
        $worst = 'ok';

        foreach ($findings as $finding) {
            $worst = $this->worstSeverity($worst, (string) ($finding['severity'] ?? 'ok'));
        }

        return $worst;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $issues
     * @param  array<int, array<string, mixed>>  $evidence
     * @return array<string, mixed>
     */
    private function finding(string $check, string $label, string $severity, array $issues, array $evidence = []): array
    {
        return [
            'check' => $check,
            'label' => $label,
            'severity' => $severity,
            'status' => $severity === 'ok' ? 'ok' : 'warning',
            'detail' => implode(' | ', $issues),
            'evidence' => $evidence,
        ];
    }

    public function worstSeverity(string $current, string $candidate): string
    {
        return (self::SEVERITY_ORDER[$candidate] ?? 0) > (self::SEVERITY_ORDER[$current] ?? 0)
            ? $candidate
            : $current;
    }
}
