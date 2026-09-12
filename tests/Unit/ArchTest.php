<?php

/*
|--------------------------------------------------------------------------
| Tests de Arquitectura
|--------------------------------------------------------------------------
|
| Comprueban reglas estructurales del código, no comportamiento. Son rápidos
| (no tocan la BD) y atrapan lo que una revisión humana deja pasar cuando el
| proyecto crece: un dd() olvidado, lógica de negocio colándose en un
| controlador o un modelo que empieza a conocer la capa HTTP.
|
| Se ejecutan con el resto de la suite, o sueltos: make test-arch
|
*/

arch('no quedan restos de depuración')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('los controladores no acceden a env() directamente')
    ->expect('App\Http\Controllers')
    ->not->toUse('env');

arch('los servicios no acceden a env() directamente')
    ->expect('App\Services')
    ->not->toUse('env');

arch('los modelos no conocen la capa HTTP')
    ->expect('App\Models')
    ->not->toUse(['App\Http\Controllers', 'App\Http\Requests', 'App\Http\Resources']);

arch('los modelos no dependen de servicios')
    ->expect('App\Models')
    ->not->toUse('App\Services')
    // Excepción conocida a corregir: Settings encripta valores llamando a
    // SecurityService desde un accessor. Debería hacerlo un cast.
    ->ignoring('App\Models\Settings');

arch('los controladores no consultan la BD directamente')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB')
    // El health check tiene que probar la conexión: es su cometido.
    ->ignoring('App\Http\Controllers\Health\HealthController');

arch('los Form Requests heredan de la base del proyecto')
    ->expect('App\Http\Requests')
    ->classes()
    ->toExtend('App\Http\Requests\BaseFormRequest')
    ->ignoring('App\Http\Requests\BaseFormRequest');

arch('los jobs se encolan')
    ->expect('App\Jobs')
    ->classes()
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue')
    ->ignoring('App\Jobs\Job');

arch('los contratos son interfaces')
    ->expect('App\Contracts')
    ->toBeInterfaces();

arch('los enums son enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('las reglas de validación implementan el contrato de Laravel')
    ->expect('App\Rules')
    ->classes()
    ->toImplement('Illuminate\Contracts\Validation\ValidationRule')
    ->ignoring('App\Rules\StrongPassword');
