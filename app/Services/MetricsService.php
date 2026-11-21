<?php

namespace App\Services;

use App\Contracts\MetricsServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Métricas Prometheus sin dependencias: los contadores HTTP viven en hashes de Redis (HINCRBY, atómicos entre
 * workers de Octane y sin estado en PHP) y el resto (colas, BD) se lee al renderizar. Ver docs/metrics.md.
 */
class MetricsService implements MetricsServiceInterface
{
    public function recordRequest(string $method, string $route, int $status, float $seconds): void
    {
        try {
            $base = $this->method($method).'|'.$route;
            $micros = (int) round(max($seconds, 0.0) * 1_000_000);
            $bucket = $this->bucketFor($seconds);

            $redis = Redis::connection($this->connection());
            $redis->command('hincrby', [$this->key('requests'), $base.'|'.$status, 1]);
            $redis->command('hincrby', [$this->key('duration_buckets'), $base.'|'.$bucket, 1]);
            $redis->command('hincrby', [$this->key('duration_sum_us'), $base, $micros]);
        } catch (Throwable) {
            // Las métricas son best-effort.
        }
    }

    public function render(): string
    {
        $ns = $this->ns();
        $out = [];

        $this->renderHttp($out, $ns);
        $this->renderQueues($out, $ns);
        $this->renderDatabase($out, $ns);

        return implode("\n", $out)."\n";
    }

    /**
     * @param  list<string>  $out
     */
    private function renderHttp(array &$out, string $ns): void
    {
        $requests = [];
        $buckets = [];
        $sums = [];

        try {
            $redis = Redis::connection($this->connection());
            $requests = (array) $redis->hgetall($this->key('requests'));
            $buckets = (array) $redis->hgetall($this->key('duration_buckets'));
            $sums = (array) $redis->hgetall($this->key('duration_sum_us'));
        } catch (Throwable) {
            // Sin Redis se exponen vacías; `*_up` lo delata abajo.
        }

        $out[] = "# HELP {$ns}_http_requests_total Peticiones HTTP atendidas por método, ruta y estado.";
        $out[] = "# TYPE {$ns}_http_requests_total counter";

        ksort($requests);

        foreach ($requests as $field => $value) {
            [$method, $route, $status] = array_pad(explode('|', (string) $field, 3), 3, '');
            $out[] = sprintf('%s_http_requests_total{method="%s",route="%s",status="%s"} %d', $ns, $this->esc($method), $this->esc($route), $this->esc($status), $value);
        }

        $out[] = "# HELP {$ns}_http_request_duration_seconds Latencia de las peticiones HTTP.";
        $out[] = "# TYPE {$ns}_http_request_duration_seconds histogram";

        /** @var array<string, array<string, int>> $perRoute */
        $perRoute = [];

        foreach ($buckets as $field => $value) {
            $pos = strrpos((string) $field, '|');
            $perRoute[substr((string) $field, 0, (int) $pos)][substr((string) $field, (int) $pos + 1)] = (int) $value;
        }

        ksort($perRoute);
        $les = array_map(fn (float $b): string => $this->fmt($b), (array) config('metrics.buckets'));

        foreach ($perRoute as $base => $counts) {
            [$method, $route] = array_pad(explode('|', (string) $base, 2), 2, '');
            $labels = sprintf('method="%s",route="%s"', $this->esc($method), $this->esc($route));
            $cumulative = 0;

            foreach ([...$les, '+Inf'] as $le) {
                $cumulative += $counts[$le] ?? 0;
                $out[] = sprintf('%s_http_request_duration_seconds_bucket{%s,le="%s"} %d', $ns, $labels, $le, $cumulative);
            }

            $out[] = sprintf('%s_http_request_duration_seconds_sum{%s} %s', $ns, $labels, $this->fmt(((int) ($sums[$base] ?? 0)) / 1_000_000));
            $out[] = sprintf('%s_http_request_duration_seconds_count{%s} %d', $ns, $labels, $cumulative);
        }
    }

    /**
     * @param  list<string>  $out
     */
    private function renderQueues(array &$out, string $ns): void
    {
        $out[] = "# HELP {$ns}_queue_size Trabajos pendientes en la cola.";
        $out[] = "# TYPE {$ns}_queue_size gauge";

        foreach ((array) config('metrics.queues', ['default']) as $queue) {
            try {
                $size = Queue::size((string) $queue);
            } catch (Throwable) {
                continue;
            }

            $out[] = sprintf('%s_queue_size{queue="%s"} %d', $ns, $this->esc((string) $queue), $size);
        }

        $out[] = "# HELP {$ns}_failed_jobs Trabajos fallidos sin reintentar (tabla failed_jobs).";
        $out[] = "# TYPE {$ns}_failed_jobs gauge";

        try {
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $out[] = "{$ns}_failed_jobs {$failed}";
        } catch (Throwable) {
            // Sin BD, `*_db_up` vale 0.
        }
    }

    /**
     * @param  list<string>  $out
     */
    private function renderDatabase(array &$out, string $ns): void
    {
        $up = 0;
        $connections = null;
        $max = null;

        try {
            DB::select('select 1');
            $up = 1;

            if (DB::connection()->getDriverName() === 'pgsql') {
                $connections = (int) (DB::selectOne('select numbackends from pg_stat_database where datname = current_database()')->numbackends ?? 0);
                $max = (int) (DB::selectOne("select setting from pg_settings where name = 'max_connections'")->setting ?? 0);
            }
        } catch (Throwable) {
            // up = 0
        }

        $out[] = "# HELP {$ns}_db_up 1 si la base de datos responde.";
        $out[] = "# TYPE {$ns}_db_up gauge";
        $out[] = "{$ns}_db_up {$up}";

        if ($connections !== null) {
            $out[] = "# HELP {$ns}_db_connections Conexiones abiertas a la base de datos.";
            $out[] = "# TYPE {$ns}_db_connections gauge";
            $out[] = "{$ns}_db_connections {$connections}";
            $out[] = "# HELP {$ns}_db_max_connections max_connections de PostgreSQL.";
            $out[] = "# TYPE {$ns}_db_max_connections gauge";
            $out[] = "{$ns}_db_max_connections {$max}";
        }
    }

    /** Cubo (le) más pequeño que contiene la latencia; `+Inf` si supera todos. */
    private function bucketFor(float $seconds): string
    {
        foreach ((array) config('metrics.buckets') as $bound) {
            if ($seconds <= (float) $bound) {
                return $this->fmt((float) $bound);
            }
        }

        return '+Inf';
    }

    private function method(string $method): string
    {
        $method = strtoupper($method);

        return in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true) ? $method : 'OTHER';
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function esc(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    private function key(string $name): string
    {
        return (string) config('metrics.key_prefix').$name;
    }

    private function connection(): string
    {
        return (string) config('metrics.redis_connection', 'default');
    }

    private function ns(): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', (string) config('metrics.namespace', 'app')) ?: 'app';
    }
}
