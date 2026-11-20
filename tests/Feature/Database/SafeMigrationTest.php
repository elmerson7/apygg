<?php

use App\Support\Database\SafeMigration;
use Illuminate\Support\Facades\DB;

it('fija lock_timeout y statement_timeout durante el bloque y los restaura', function () {
    $migration = new class extends SafeMigration
    {
        /** @return array{string, string} */
        public function dentro(): array
        {
            return $this->withTimeouts(fn () => [
                DB::selectOne('SHOW lock_timeout')->lock_timeout,
                DB::selectOne('SHOW statement_timeout')->statement_timeout,
            ], '3s', '45s');
        }
    };

    $antes = DB::selectOne('SHOW lock_timeout')->lock_timeout;

    expect($migration->dentro())->toBe(['3s', '45s'])
        ->and(DB::selectOne('SHOW lock_timeout')->lock_timeout)->toBe($antes);
});

it('restaura los tiempos aunque el bloque falle', function () {
    $migration = new class extends SafeMigration
    {
        public function falla(): void
        {
            $this->withTimeouts(fn () => throw new RuntimeException('boom'), '2s', '2s');
        }
    };
    $antes = DB::selectOne('SHOW statement_timeout')->statement_timeout;

    expect(fn () => $migration->falla())->toThrow(RuntimeException::class)
        ->and(DB::selectOne('SHOW statement_timeout')->statement_timeout)->toBe($antes);
});
