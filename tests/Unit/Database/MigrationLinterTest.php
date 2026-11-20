<?php

use App\Support\Database\MigrationLinter;

function migracion(string $up, string $head = ''): string
{
    return "<?php\n{$head}\nreturn new class extends Migration {\n    public function up(): void\n    {\n{$up}\n    }\n\n    public function down(): void\n    {\n        Schema::table('x', fn (\$t) => \$t->dropColumn('a'));\n    }\n};\n";
}

function reglas(string $src, array $large = ['api_logs']): array
{
    return array_column((new MigrationLinter)->lint($src, $large), 'rule');
}

it('permite crear una tabla nueva con índices y columnas obligatorias', function () {
    $src = migracion("Schema::create('cosas', function (Blueprint \$table) {\n\$table->id();\n\$table->string('name');\n\$table->index('name');\n\$table->timestamps();\n});");
    expect(reglas($src))->toBe([]);
});

it('permite añadir una columna nullable o con default y un índice en tabla pequeña', function () {
    $src = migracion("Schema::table('users', function (Blueprint \$table) {\n\$table->string('a')->nullable();\n\$table->boolean('b')->default(false);\n});\nSchema::table('pequena', function (Blueprint \$table) {\n\$table->index('c');\n});");
    expect(reglas($src))->toBe([]);
});

it('detecta change, renameColumn y dropColumn en up', function () {
    $src = migracion("Schema::table('t', function (Blueprint \$table) {\n\$table->string('a', 10)->nullable()->change();\n\$table->renameColumn('a', 'b');\n\$table->dropColumn('c');\n});");
    expect(reglas($src))->toBe(['change-type', 'rename-column', 'drop-column']);
});

it('detecta una columna NOT NULL sin default sobre tabla existente', function () {
    $src = migracion("Schema::table('t', function (Blueprint \$table) {\n\$table->string('a');\n});");
    expect(reglas($src))->toBe(['not-null-no-default']);
});

it('detecta SET NOT NULL directo', function () {
    $src = migracion("DB::statement('ALTER TABLE t ALTER COLUMN a SET NOT NULL');");
    expect(reglas($src))->toBe(['not-null-no-default']);
});

it('detecta índices sobre tablas grandes sin CONCURRENTLY pero permite CONCURRENTLY', function () {
    $malo = migracion("Schema::table('api_logs', function (Blueprint \$table) {\n\$table->index('a');\n});\nDB::statement('CREATE INDEX x ON api_logs (b)');");
    $bueno = migracion("DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS x ON api_logs (b)');");
    expect(reglas($malo))->toBe(['index-not-concurrent', 'index-not-concurrent'])
        ->and(reglas($bueno))->toBe([]);
});

it('ignora los patrones peligrosos de down', function () {
    expect(reglas(migracion("Schema::table('t', fn (\$t) => \$t->string('a')->nullable());")))->toBe([]);
});

it('respeta la excepción inline por línea y por archivo', function () {
    $linea = migracion("Schema::table('t', function (Blueprint \$table) {\n// migration-lint: ignore rename-column -- tabla vacía\n\$table->renameColumn('a', 'b');\n});");
    $archivo = migracion("Schema::table('t', fn (\$t) => \$t->dropColumn('a'));", "// migration-lint: ignore drop-column -- ya sin uso desde 1.4\n");
    expect(reglas($linea))->toBe([])->and(reglas($archivo))->toBe([]);
});

it('informa del número de línea', function () {
    $v = (new MigrationLinter)->lint(migracion("Schema::table('t', fn (\$t) => \$t->dropColumn('a'));"));
    expect($v)->toHaveCount(1)->and($v[0]['line'])->toBe(6);
});

it('hace pasar el linter sobre las migraciones reales del repo', function () {
    $this->artisan('migrations:lint')->assertSuccessful();
});

it('hace fallar el comando ante una migración nueva peligrosa y respeta las excepciones de config', function () {
    $dir = sys_get_temp_dir().'/mig-lint-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/2099_01_01_000001_mala.php', migracion("Schema::table('t', fn (\$t) => \$t->renameColumn('a', 'b'));"));

    $this->artisan('migrations:lint', ['--path' => $dir])->assertFailed();

    config(['migration-lint.exceptions' => ['2099_01_01_000001_mala' => ['rename-column']]]);
    $this->artisan('migrations:lint', ['--path' => $dir])->assertSuccessful();

    unlink($dir.'/2099_01_01_000001_mala.php');
    rmdir($dir);
});
