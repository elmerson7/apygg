<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('ninguna columna de la aplicación usa json: en PostgreSQL se usa jsonb', function () {
    $columnasJson = DB::select(
        "select table_name, column_name from information_schema.columns
         where table_schema = current_schema() and data_type = 'json'"
    );

    expect(collect($columnasJson)->map(fn ($c) => "{$c->table_name}.{$c->column_name}")->all())->toBe([]);
});
