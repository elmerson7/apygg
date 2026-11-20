<?php

namespace App\Support\Modules;

use InvalidArgumentException;

/**
 * Catálogo de módulos: lee `modules/*.php` bajo una raíz (por defecto la del proyecto; los tests y la prueba de
 * «quitar cada módulo» la apuntan a una copia). Sin estado compartido: se construye por uso.
 */
final class ModuleRegistry
{
    public function __construct(private readonly string $root) {}

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Todos los manifiestos, por nombre. Los de módulos ya quitados se conservan a propósito (`module:list`
     * los muestra como «quitado» y la baja es idempotente).
     *
     * @return array<string, Module>
     */
    public function all(): array
    {
        $modules = [];

        foreach (glob($this->root.'/modules/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $data = require $file;

            if (! is_array($data)) {
                throw new InvalidArgumentException("El manifiesto modules/{$name}.php debe devolver un array");
            }

            $modules[$name] = Module::fromArray($name, $data);
        }

        ksort($modules);

        return $modules;
    }

    public function find(string $name): ?Module
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Rutas existentes que el módulo reclama (archivos o carpetas, relativas a la raíz).
     *
     * @return list<string>
     */
    public function existingPaths(Module $module, bool $withMigrations = false): array
    {
        $paths = [];

        foreach ($withMigrations ? $module->claims() : $module->files as $entry) {
            if (str_contains($entry, '*')) {
                foreach (glob($this->root.'/'.$entry) ?: [] as $match) {
                    $paths[] = $this->relative($match);
                }

                continue;
            }

            if (file_exists($this->root.'/'.$entry)) {
                $paths[] = $entry;
            }
        }

        return array_values(array_unique($paths));
    }

    /** El núcleo siempre está; un opcional está mientras exista alguna de sus rutas. */
    public function isPresent(Module $module): bool
    {
        return $module->core || $this->existingPaths($module) !== [];
    }

    /**
     * Módulos presentes que dependen de `$name` (impiden quitarlo).
     *
     * @return list<string>
     */
    public function presentDependents(string $name): array
    {
        $found = [];

        foreach ($this->all() as $other) {
            if ($other->name !== $name && in_array($name, $other->depends, true) && $this->isPresent($other)) {
                $found[] = $other->name;
            }
        }

        return $found;
    }

    /**
     * Módulos que dependen de `$name` (presentes o no), para mostrarlos en el listado.
     *
     * @return list<string>
     */
    public function dependents(string $name): array
    {
        $found = [];

        foreach ($this->all() as $other) {
            if (in_array($name, $other->depends, true)) {
                $found[] = $other->name;
            }
        }

        return $found;
    }

    /**
     * Todos los archivos (no carpetas) bajo una ruta reclamada, relativos a la raíz.
     *
     * @return list<string>
     */
    public function filesUnder(string $relative): array
    {
        $absolute = $this->root.'/'.$relative;

        if (is_file($absolute)) {
            return [$relative];
        }

        $files = [];

        if (is_dir($absolute)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $this->relative($file->getPathname());
                }
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $absolute): string
    {
        return ltrim(substr($absolute, strlen($this->root)), '/');
    }
}
