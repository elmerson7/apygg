<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Verificación de Timezone ===\n\n";

echo 'Timezone configurado: '.config('app.timezone')."\n";
echo 'Fecha actual (PHP): '.date('Y-m-d H:i:s T')."\n";
echo 'Fecha actual (Carbon): '.\Carbon\Carbon::now()."\n";
echo 'Fecha actual (Carbon UTC): '.\Carbon\Carbon::now('UTC')."\n";
echo 'ISO String: '.\Carbon\Carbon::now()->toISOString()."\n";

echo "\n=== Test de logs ===\n";

use App\Models\Logs\AuthEvent;

// Crear un evento de prueba
$event = new AuthEvent([
    'user_id' => '01k53e2e4eszsxejfn7hcdawjg',
    'event' => 'test',
    'result' => 'success',
    'ip' => '127.0.0.1',
    'trace_id' => 'test-trace-id',
]);

echo 'Timestamp before save: '.($event->created_at ?? 'null')."\n";
$event->save();
echo 'Timestamp after save: '.$event->created_at."\n";
echo 'Timestamp ISO: '.$event->created_at->toISOString()."\n";

// Contar eventos
$count = AuthEvent::count();
echo "\nTotal eventos en DB: $count\n";

// Mostrar últimos eventos
$events = AuthEvent::orderBy('created_at', 'desc')->limit(3)->get();
echo "\nÚltimos eventos:\n";
foreach ($events as $event) {
    echo "- {$event->event} ({$event->result}) - {$event->created_at} - {$event->created_at->toISOString()}\n";
}
