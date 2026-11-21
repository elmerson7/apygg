<?php

namespace App\Support\Modules;

/**
 * Un módulo del boilerplate tal como lo describe su manifiesto `modules/<nombre>.php` (docs/modules.md).
 *
 * Valor inmutable: sin estado ni acceso a disco (eso es de `ModuleRegistry`).
 */
final class Module
{
    /**
     * @param  list<string>  $depends  Módulos de los que depende (deben estar presentes para que este funcione)
     * @param  list<string>  $files  Rutas (archivo, carpeta entera o patrón con `*`) relativas a la raíz que le pertenecen
     * @param  list<string>  $migrations  Migraciones que crean sus tablas (se conservan por defecto: ver `--purge-migrations`)
     * @param  list<string>  $tables  Tablas que crea (la migración de baja las elimina)
     * @param  list<string>  $permissions  Permisos que siembra (informativo; se quitan con marcadores)
     * @param  list<string>  $env  Variables de .env.example (informativo; se quitan con marcadores)
     * @param  list<string>  $packages  Paquetes de composer que solo usa este módulo (informativo: `composer remove`)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly bool $core,
        public readonly array $depends,
        public readonly array $files,
        public readonly array $migrations,
        public readonly array $tables,
        public readonly array $permissions,
        public readonly array $env,
        public readonly array $packages,
        public readonly bool $purgeable = true,
    ) {}

    /**
     * Todo lo que el módulo reclama como suyo (archivos y migraciones), para el inventario.
     *
     * @return list<string>
     */
    public function claims(): array
    {
        return [...$this->files, ...$this->migrations];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $name, array $data): self
    {
        $list = static fn (string $key): array => array_values(array_map('strval', (array) ($data[$key] ?? [])));

        return new self(
            name: $name,
            description: (string) ($data['description'] ?? ''),
            core: (bool) ($data['core'] ?? false),
            depends: $list('depends'),
            files: $list('files'),
            migrations: $list('migrations'),
            tables: $list('tables'),
            permissions: $list('permissions'),
            env: $list('env'),
            packages: $list('packages'),
            purgeable: (bool) ($data['purgeable'] ?? true),
        );
    }
}
