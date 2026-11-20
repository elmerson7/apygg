<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clase base opcional para migraciones sin parada: fija `lock_timeout` y `statement_timeout` mientras
 * corre el bloque, de modo que un `ALTER TABLE` que no consigue el bloqueo falla pronto en vez de
 * encolar escrituras detrás de sí. Valores en `config/migration-lint.php`.
 *
 * Uso: `return new class extends SafeMigration { public function up(): void {
 * $this->withTimeouts(fn () => Schema::table(...)); } };`
 *
 * Usa `SET` de sesión (no `SET LOCAL`) para que valga también con `$withinTransaction = false`
 * (CREATE INDEX CONCURRENTLY), y restaura los valores previos al terminar.
 */
abstract class SafeMigration extends Migration
{
    protected function withTimeouts(Closure $callback, ?string $lock = null, ?string $statement = null): mixed
    {
        $lock ??= (string) config('migration-lint.lock_timeout', '5s');
        $statement ??= (string) config('migration-lint.statement_timeout', '60s');

        $prevLock = (string) (DB::selectOne('SHOW lock_timeout')->lock_timeout ?? '0');
        $prevStatement = (string) (DB::selectOne('SHOW statement_timeout')->statement_timeout ?? '0');

        DB::statement('SET lock_timeout = '.DB::getPdo()->quote($lock));
        DB::statement('SET statement_timeout = '.DB::getPdo()->quote($statement));

        try {
            return $callback();
        } finally {
            DB::statement('SET lock_timeout = '.DB::getPdo()->quote($prevLock));
            DB::statement('SET statement_timeout = '.DB::getPdo()->quote($prevStatement));
        }
    }
}
