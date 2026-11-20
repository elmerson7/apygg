<?php

namespace App\Services;

use App\Contracts\AuditServiceInterface;
use App\Contracts\MaintenanceServiceInterface;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Modo mantenimiento de la API (docs/maintenance-mode.md). El estado vive en la caché compartida
 * (`maintenance.store`), no en un archivo: sin estado local, válido con Octane y varias réplicas.
 */
class MaintenanceService implements MaintenanceServiceInterface
{
    public function __construct(private readonly AuditServiceInterface $audit) {}

    public function state(): ?array
    {
        if (config('maintenance.enabled')) {
            return $this->shape(
                config('maintenance.message'),
                config('maintenance.until'),
                now()->toIso8601String(),
                'env',
            );
        }

        $stored = $this->store()->get((string) config('maintenance.cache_key'));

        if (! is_array($stored)) {
            return null;
        }

        return $this->shape($stored['message'] ?? null, $stored['until'] ?? null, (string) ($stored['since'] ?? now()->toIso8601String()), 'cache');
    }

    public function enable(?string $message, ?CarbonInterface $until, ?User $by = null): void
    {
        $payload = [
            'message' => $message,
            'until' => $until?->toIso8601String(),
            'since' => now()->toIso8601String(),
        ];

        $key = (string) config('maintenance.cache_key');

        // Con fin estimado, la entrada caduca sola poco después (por si nadie lo apaga).
        $until !== null && $until->isFuture()
            ? $this->store()->put($key, $payload, $until->copy()->addMinutes(5))
            : $this->store()->forever($key, $payload);

        $this->audit->recordAction('maintenance.enable', null, null, [
            'message' => $message,
            'until' => $payload['until'],
        ]);
    }

    public function disable(?User $by = null): void
    {
        $this->store()->forget((string) config('maintenance.cache_key'));

        $this->audit->recordAction('maintenance.disable');
    }

    public function allows(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach ((array) config('maintenance.allow_paths', []) as $pattern) {
            if (is_string($pattern) && fnmatch($pattern, $path)) {
                return true;
            }
        }

        $ips = (array) config('maintenance.allow_ips', []);
        $ip = $request->ip();

        if ($ips !== [] && $ip !== null && IpUtils::checkIp($ip, $ips)) {
            return true;
        }

        $roles = (array) config('maintenance.allow_roles', []);

        if ($roles !== [] && $request->bearerToken() !== null) {
            try {
                $user = Auth::guard('api')->user();

                return $user instanceof User && $user->hasAnyRole($roles);
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    private function store(): Repository
    {
        $name = config('maintenance.store');

        return Cache::store(is_string($name) && $name !== '' ? $name : null);
    }

    /**
     * @return array{message: string|null, until: string|null, since: string, source: string, retry_after: int}
     */
    private function shape(mixed $message, mixed $until, string $since, string $source): array
    {
        $untilAt = null;

        if (is_string($until) && $until !== '') {
            try {
                $untilAt = Carbon::parse($until);
            } catch (\Throwable) {
                $untilAt = null;
            }
        }

        $retry = $untilAt !== null && $untilAt->isFuture()
            ? max(1, (int) now()->diffInSeconds($untilAt, true))
            : max(1, (int) config('maintenance.retry_after', 60));

        return [
            'message' => is_string($message) && $message !== '' ? $message : null,
            'until' => $untilAt?->toIso8601String(),
            'since' => $since,
            'source' => $source,
            'retry_after' => $retry,
        ];
    }
}
