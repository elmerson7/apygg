<?php

namespace App\Support\Modules;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Esqueleto de un módulo opcional nuevo (checklist de docs/modules.md): manifiesto, interfaz + servicio + binding,
 * controller, Form Request, ruta, permiso, test y doc. Todo lo que toca en archivos compartidos va con
 * marcadores `module:<nombre>` para que `module:remove` lo pueda quitar.
 */
final class ModuleScaffolder
{
    public function __construct(private readonly string $root) {}

    /**
     * @return list<string> rutas creadas o editadas (relativas)
     */
    public function create(string $name, bool $dryRun = false): array
    {
        if (! preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $name)) {
            throw new RuntimeException('El nombre debe ir en kebab-case (p. ej. «mi-modulo»).');
        }

        if (is_file($this->root."/modules/{$name}.php")) {
            throw new RuntimeException("El módulo «{$name}» ya existe (modules/{$name}.php).");
        }

        $studly = Str::studly($name);
        $vars = ['{{name}}' => $name, '{{Studly}}' => $studly, '{{snake}}' => str_replace('-', '_', $name)];

        $new = [
            "app/Contracts/{$studly}ServiceInterface.php" => $this->interface(),
            "app/Services/{$studly}Service.php" => $this->service(),
            "app/Http/Controllers/{$studly}/{$studly}Controller.php" => $this->controller(),
            "app/Http/Requests/{$studly}/Store{$studly}Request.php" => $this->request(),
            "routes/api/{$name}.php" => $this->routes(),
            "tests/Feature/Modulos/{$studly}Test.php" => $this->test(),
            "docs/{$name}.md" => $this->doc(),
            "modules/{$name}.php" => $this->manifest($name, $studly),
        ];

        $edits = [
            'app/Providers/ContractServiceProvider.php' => [
                ['use Illuminate\Support\ServiceProvider;', "use App\\Contracts\\{$studly}ServiceInterface;\nuse App\\Services\\{$studly}Service;\nuse Illuminate\\Support\\ServiceProvider;"],
                ['        // Estado POR PETICIÓN/JOB', "        \$this->app->bind({$studly}ServiceInterface::class, {$studly}Service::class); // module:{$name}\n        // Estado POR PETICIÓN/JOB"],
            ],
            'routes/api.php' => [
                ['// ─────────────────────────────────────────────────────────────────────────────────', "require __DIR__.'/api/{$name}.php'; // module:{$name}\n// ─────────────────────────────────────────────────────────────────────────────────", true],
            ],
            'database/seeders/PermissionSeeder.php' => [
                ['            // Sistema', "            ['name' => '{$name}.read', 'display_name' => 'Ver {$studly}', 'description' => 'Permite consultar el módulo {$name}'], // module:{$name}\n            // Sistema"],
            ],
            'tests/Arch/ArchTest.php' => [
                ["    return [\n        'App\\Services\\AuthService'", "    return [\n        'App\\Services\\{$studly}Service' => 'App\\Contracts\\{$studly}ServiceInterface', // module:{$name}\n        'App\\Services\\AuthService'"],
            ],
        ];

        $touched = array_keys($new);

        foreach ($edits as $file => $_) {
            $touched[] = $file;
        }

        if ($dryRun) {
            return $touched;
        }

        // Primero se comprueban todas las anclas: o se hace todo o no se toca nada.
        $contents = [];

        foreach ($edits as $file => $pairs) {
            $text = (string) @file_get_contents($this->root.'/'.$file);

            foreach ($pairs as $pair) {
                [$anchor, $replacement] = $pair;
                $position = ($pair[2] ?? false) ? strrpos($text, $anchor) : strpos($text, $anchor);

                if ($position === false) {
                    throw new RuntimeException('No encuentro la ancla «'.trim((string) strtok($anchor, "\n")).'» en '.$file.': añádelo a mano (docs/modules.md).');
                }

                $text = substr($text, 0, $position).$replacement.substr($text, $position + strlen($anchor));
            }

            $contents[$file] = $text;
        }

        foreach ($new as $file => $body) {
            $path = $this->root.'/'.$file;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, strtr($body, $vars));
        }

        foreach ($contents as $file => $text) {
            file_put_contents($this->root.'/'.$file, $text);
        }

        return $touched;
    }

    private function manifest(string $name, string $studly): string
    {
        return <<<PHP
        <?php

        // Manifiesto del módulo «{$name}» (docs/modules.md): qué le pertenece y de qué depende.
        return [
            'description' => 'Describe aquí qué hace el módulo.',
            'core' => false,
            'depends' => [],
            'files' => [
                'app/Contracts/{$studly}ServiceInterface.php',
                'app/Services/{$studly}Service.php',
                'app/Http/Controllers/{$studly}',
                'app/Http/Requests/{$studly}',
                'routes/api/{$name}.php',
                'tests/Feature/Modulos/{$studly}Test.php',
                'docs/{$name}.md',
            ],
            'tables' => [],
            'permissions' => ['{$name}.read'],
            'env' => [],
            'packages' => [],
        ];

        PHP;
    }

    private function interface(): string
    {
        return <<<'PHP'
        <?php

        namespace App\Contracts;

        interface {{Studly}}ServiceInterface
        {
            /**
             * @return array<string, mixed>
             */
            public function overview(): array;
        }

        PHP;
    }

    private function service(): string
    {
        return <<<'PHP'
        <?php

        namespace App\Services;

        use App\Contracts\{{Studly}}ServiceInterface;

        class {{Studly}}Service implements {{Studly}}ServiceInterface
        {
            public function overview(): array
            {
                return ['module' => '{{name}}'];
            }
        }

        PHP;
    }

    private function controller(): string
    {
        return <<<'PHP'
        <?php

        namespace App\Http\Controllers\{{Studly}};

        use App\Contracts\{{Studly}}ServiceInterface;
        use App\Http\Controllers\Controller;
        use Illuminate\Http\JsonResponse;

        class {{Studly}}Controller extends Controller
        {
            public function __construct(protected {{Studly}}ServiceInterface $service) {}

            public function index(): JsonResponse
            {
                return $this->sendSuccess($this->service->overview());
            }
        }

        PHP;
    }

    private function request(): string
    {
        return <<<'PHP'
        <?php

        namespace App\Http\Requests\{{Studly}};

        use App\Http\Requests\BaseFormRequest;

        class Store{{Studly}}Request extends BaseFormRequest
        {
            /**
             * @return array<string, mixed>
             */
            public function rules(): array
            {
                return [
                    'name' => ['required', 'string', 'max:255'],
                ];
            }
        }

        PHP;
    }

    private function routes(): string
    {
        return <<<'PHP'
        <?php

        use App\Http\Controllers\{{Studly}}\{{Studly}}Controller;
        use Illuminate\Support\Facades\Route;

        /*
        |--------------------------------------------------------------------------
        | Módulo {{name}} (docs/{{name}}.md)
        |--------------------------------------------------------------------------
        */
        Route::middleware(['auth:api', 'permission:{{name}}.read,api'])
            ->get('/{{name}}', [{{Studly}}Controller::class, 'index'])
            ->name('{{name}}.index');

        PHP;
    }

    private function test(): string
    {
        return <<<'PHP'
        <?php

        use App\Models\Permission;
        use App\Models\User;
        use Illuminate\Foundation\Testing\RefreshDatabase;
        use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

        uses(RefreshDatabase::class);

        test('quien tiene {{name}}.read ve el resumen del módulo', function () {
            $user = User::factory()->create();
            $user->permissions()->attach(Permission::where('name', '{{name}}.read')->value('id'));

            $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user)])
                ->getJson('/{{name}}')
                ->assertOk()
                ->assertJsonPath('data.module', '{{name}}');
        });

        test('sin el permiso {{name}}.read responde 403', function () {
            $user = User::factory()->create();

            $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user)])
                ->getJson('/{{name}}')
                ->assertForbidden();
        });

        test('sin token responde 401', function () {
            $this->getJson('/{{name}}')->assertUnauthorized();
        });

        PHP;
    }

    private function doc(): string
    {
        return <<<'MD'
        # Módulo {{name}}

        Qué hace, endpoints, permisos y variables de entorno.

        | Endpoint | Permiso |
        |---|---|
        | `GET /{{name}}` | `{{name}}.read` |

        ## Quitar el módulo

        `make module-remove name={{name}}` (simulación: añade `DRY=1`). Quita sus archivos y las líneas marcadas con
        `module:{{name}}` de los archivos compartidos. Ver `docs/modules.md`.
        MD;
    }
}
