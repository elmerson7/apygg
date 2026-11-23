<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsTypedInput;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * ScanLazyLoadingCommand
 *
 * Recorre todas las rutas GET, las ejecuta con `preventLazyLoading` activo y
 * reporta dónde se dispara lazy loading — es decir, dónde hay un N+1 esperando
 * a crecer con los datos.
 *
 * Es la forma barata de encontrar problemas de rendimiento antes de que los
 * encuentre el cliente con 20.000 filas en la tabla.
 *
 * Los parámetros de ruta se resuelven automáticamente: `{user}` busca el modelo
 * `User`, `{report}` busca `Report`, y toma un id real de la BD. Si no puede
 * resolverlo, la ruta se salta e informa (no se inventa un id).
 *
 * Uso:
 *   php artisan scan:lazy-loading
 *   php artisan scan:lazy-loading --prefix=admin
 *   php artisan scan:lazy-loading --delay=0
 */
class ScanLazyLoadingCommand extends Command
{
    use ReadsTypedInput;

    protected $signature = 'scan:lazy-loading
                            {--prefix= : Analizar solo las rutas que empiecen por este prefijo}
                            {--delay=200 : Milisegundos entre peticiones, para no chocar con el rate limiter}
                            {--user= : ID del usuario con el que autenticarse (por defecto, un admin)}';

    protected $description = 'Detectar lazy loading (N+1) recorriendo los endpoints GET';

    /** @var array<int, array<string, string>> */
    private array $violations = [];

    /** @var array<int, array<string, string>> */
    private array $errors = [];

    /** @var array<int, string> */
    private array $skipped = [];

    private int $ok = 0;

    public function handle(): int
    {
        $user = $this->resolveUser();

        if (! $user) {
            $this->error('No se encontró ningún usuario con el que autenticarse.');
            $this->line('Crea uno con: php artisan user:create');

            return self::FAILURE;
        }

        // Sin esto el escáner no detecta nada: es lo que convierte un lazy load
        // silencioso en una excepción visible.
        Model::preventLazyLoading(true);

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteInstance $route) => in_array('GET', $route->methods(), true))
            ->when($this->option('prefix'), fn ($c) => $c->filter(
                fn (RouteInstance $route) => str_starts_with($route->uri(), ($this->stringOption('prefix') ?? ''))
            ));

        $this->info("Analizando {$routes->count()} rutas GET como '{$user->email}'...");
        $this->newLine();

        $delay = (int) $this->option('delay') * 1000;
        $bar = $this->output->createProgressBar($routes->count());

        foreach ($routes as $route) {
            $this->scanRoute($route, $user);
            $bar->advance();

            if ($delay > 0) {
                usleep($delay);
            }
        }

        $bar->finish();
        $this->newLine(2);

        Model::preventLazyLoading(false);

        return $this->report();
    }

    private function resolveUser(): ?User
    {
        $id = $this->stringOption('user');
        if ($id !== null && $id !== '') {
            return User::query()->find($id);
        }

        return User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first()
            ?? User::first();
    }

    private function scanRoute(RouteInstance $route, User $user): void
    {
        $uri = $route->uri();
        $filled = $this->fillParameters($uri, $route->parameterNames());

        if ($filled === null) {
            $this->skipped[] = $uri;

            return;
        }

        try {
            $request = Request::create('/'.ltrim($filled, '/'), 'GET');
            $request->setUserResolver(fn () => $user);
            auth('api')->setUser($user);

            $response = app(Kernel::class)->handle($request);
            $status = $response->getStatusCode();

            match (true) {
                in_array($status, [404, 429], true) => $this->skipped[] = $uri,
                $status >= 500 => $this->errors[] = ['uri' => $uri, 'error' => "HTTP {$status}"],
                in_array($status, [401, 403], true) => $this->errors[] = ['uri' => $uri, 'error' => "HTTP {$status} (auth/permiso)"],
                default => $this->ok++,
            };
        } catch (LazyLoadingViolationException $e) {
            // La excepción no expone el modelo ni la relación como métodos:
            // solo vienen en el mensaje ("Attempted to lazy load [x] on model [Y]").
            $this->violations[] = [
                'uri' => $uri,
                'name' => $route->getName() ?? '',
                'model' => $this->extractFromViolation($e->getMessage(), 'model'),
                'relation' => $this->extractFromViolation($e->getMessage(), 'relation'),
            ];
        } catch (\Throwable $e) {
            $this->errors[] = ['uri' => $uri, 'error' => Str::limit($e->getMessage(), 120)];
        }
    }

    /**
     * Extraer el modelo o la relación del mensaje de la violación.
     */
    private function extractFromViolation(string $message, string $part): string
    {
        // "Attempted to lazy load [relacion] on model [App\Models\Foo] ..."
        if (preg_match('/lazy load \[(.+?)\] on model \[(.+?)\]/', $message, $m) === 1) {
            return $part === 'relation' ? $m[1] : class_basename($m[2]);
        }

        return '?';
    }

    /**
     * Sustituir los parámetros de la URI por ids reales de la BD.
     *
     * El nombre del parámetro se convierte a modelo (`{report}` → `Report`) y
     * de ahí a su tabla. Si no hay modelo o no hay filas, se devuelve null y la
     * ruta no se prueba: inventar un id solo produciría 404 ruidosos.
     *
     * @param  array<int, string>  $parameters
     */
    private function fillParameters(string $uri, array $parameters): ?string
    {
        $filled = $uri;

        foreach ($parameters as $parameter) {
            // Parámetro opcional: se elimina del path
            if (str_contains($uri, '{'.$parameter.'?}')) {
                $filled = str_replace('/{'.$parameter.'?}', '', $filled);

                continue;
            }

            $id = $this->resolveParameterValue($parameter);

            if ($id === null) {
                return null;
            }

            $filled = str_replace('{'.$parameter.'}', (string) $id, $filled);
        }

        return $filled;
    }

    private function resolveParameterValue(string $parameter): int|string|null
    {
        foreach ($this->candidateTables($parameter) as $table) {
            try {
                if ($value = DB::table($table)->value('id')) {
                    return $value;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Tablas candidatas para un nombre de parámetro.
     *
     * `{mailAccount}` → App\Models\Mail\MailAccount → mail_accounts
     * `{id}` es ambiguo, así que no se resuelve.
     *
     * @return array<int, string>
     */
    private function candidateTables(string $parameter): array
    {
        // Quitar sufijos habituales: {userId} → user
        $base = preg_replace('/(_id|Id)$/', '', $parameter) ?? $parameter;

        if ($base === '' || strtolower($base) === 'id') {
            return [];
        }

        $studly = Str::studly(Str::singular($base));
        $tables = [];

        foreach ($this->modelNamespaces() as $namespace) {
            $class = $namespace.'\\'.$studly;

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $tables[] = (new $class)->getTable();
            }
        }

        // Último recurso: la convención de nombres de Laravel
        $tables[] = Str::snake(Str::pluralStudly($studly));

        return array_unique($tables);
    }

    /**
     * @return array<int, string>
     */
    private function modelNamespaces(): array
    {
        return ['App\\Models', 'App\\Models\\Mail', 'App\\Models\\Llm', 'App\\Models\\Logs'];
    }

    private function report(): int
    {
        $this->info(sprintf(
            'OK: %d  ·  Sin datos para probar: %d  ·  Errores: %d  ·  Violaciones: %d',
            $this->ok,
            count($this->skipped),
            count($this->errors),
            count($this->violations),
        ));

        $this->newLine();
        $this->line('<fg=yellow>═══ LAZY LOADING (N+1) ═══</>');

        if ($this->violations === []) {
            $this->line('  Ninguna. 🎉');
        } else {
            $this->table(
                ['Ruta', 'Modelo', 'Relación'],
                array_map(fn (array $v) => [$v['uri'], $v['model'], $v['relation']], $this->violations)
            );
            $this->line('  Solución: añade la relación al with()/withCount() del listado correspondiente.');
        }

        if ($this->errors !== []) {
            $this->newLine();
            $this->line('<fg=red>═══ ERRORES ═══</>');
            $this->table(['Ruta', 'Error'], array_map(fn (array $e) => [$e['uri'], $e['error']], $this->errors));
        }

        if ($this->skipped !== []) {
            $this->newLine();
            $this->line('<fg=gray>═══ SIN PROBAR (faltan datos para sus parámetros) ═══</>');
            $this->line('  '.implode("\n  ", $this->skipped));
        }

        // Devuelve fallo si hay violaciones: así se puede usar en CI.
        return $this->violations === [] ? self::SUCCESS : self::FAILURE;
    }
}
