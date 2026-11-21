<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/**
 * Todo comando de limpieza/retención del boilerplate debe estar en el schedule (routes/console.php);
 * si no, los datos caducados se acumulan sin que nadie lo note.
 */
test('todo comando de limpieza o retención está programado', function () {
    // Manuales a propósito: datos de prueba masivos (no es retención).
    $manuales = ['seed:load:purge'];

    $programados = collect(app(Schedule::class)->events())
        ->map(fn (Event $e) => preg_match("/artisan['\"]?\s+(\S+)/", (string) $e->command, $m) ? $m[1] : '')
        ->all();

    $limpieza = collect(Artisan::all())
        ->keys()
        ->filter(fn (string $n) => preg_match('/(clean|prune|purge|sweep|cleanup)/', $n) === 1)
        // Solo los comandos propios de la app, no los de vendor (queue:prune-batches, etc.).
        ->filter(fn (string $n) => str_starts_with(get_class(Artisan::all()[$n]), 'App\\'))
        ->reject(fn (string $n) => in_array($n, $manuales, true))
        ->values();

    expect($limpieza)->not->toBeEmpty();

    foreach ($limpieza as $nombre) {
        expect($programados)->toContain($nombre);
    }
});

test('los secretos antiguos de webhooks se limpian a diario', function () {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => str_contains((string) $e->command, 'webhooks:cleanup-old-secrets'));

    expect($evento)->not->toBeNull()->and($evento->expression)->toBe('15 2 * * *');
});
