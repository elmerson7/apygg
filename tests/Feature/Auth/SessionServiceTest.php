<?php

use App\Contracts\SessionServiceInterface;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Captura lo que se registra por canal: lista de [canal, nivel, mensaje, contexto].
 *
 * @return ArrayObject<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
 */
function capturarLogs(): ArrayObject
{
    $registro = new ArrayObject;
    Log::shouldReceive('channel')->andReturnUsing(function (string $canal) use ($registro) {
        return new class($canal, $registro)
        {
            public function __construct(private string $canal, private ArrayObject $registro) {}

            /** @param array{0: string, 1?: array<string, mixed>} $args */
            public function __call(string $nivel, array $args): void
            {
                $this->registro[] = [$this->canal, $nivel, (string) $args[0], (array) ($args[1] ?? [])];
            }
        };
    });

    return $registro;
}

function sesiones(): SessionServiceInterface
{
    return app(SessionServiceInterface::class);
}

test('start crea una sesión activa con uuid, jti de refresh de 48 hex, dispositivo e IP', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, '10.0.0.5', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36');

    expect($s->user_id)->toBe($user->id)
        ->and($s->public_id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($s->refresh_jti)->toMatch('/^[0-9a-f]{48}$/')
        ->and($s->device)->toBe('Chrome en Windows')
        ->and($s->ip)->toBe('10.0.0.5')
        ->and($s->revoked_at)->toBeNull()
        ->and($s->last_used_at)->not->toBeNull();
});

test('start recorta el user agent a 255 caracteres y acepta sesión sin user agent', function () {
    $user = User::factory()->create();

    $largo = sesiones()->start($user, null, str_repeat('a', 300));
    $sin = sesiones()->start($user, null, null);

    expect(mb_strlen((string) $largo->user_agent))->toBe(255)
        ->and($sin->user_agent)->toBeNull()
        ->and($sin->device)->toBeNull();
});

test('cada jti de refresh nuevo es distinto', function () {
    expect(sesiones()->newRefreshJti())->not->toBe(sesiones()->newRefreshJti());
});

test('listFor solo muestra sesiones activas del usuario, la más reciente primero, y marca la actual', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();

    $vieja = sesiones()->start($user, '1.1.1.1', 'Mozilla/5.0 (iPhone) Safari/604.1');
    $nueva = sesiones()->start($user, '2.2.2.2', null);
    $revocada = sesiones()->start($user, '3.3.3.3', null);
    sesiones()->start($otro, '4.4.4.4', null);
    $vieja->forceFill(['last_used_at' => now()->subHour()])->save();
    $nueva->forceFill(['last_used_at' => now()])->save();
    $revocada->forceFill(['last_used_at' => now()->addMinute()])->save();
    sesiones()->revokeBySid($revocada->public_id);

    $lista = sesiones()->listFor($user, $vieja->public_id);

    expect(array_column($lista, 'id'))->toBe([$nueva->public_id, $vieja->public_id])
        ->and(array_column($lista, 'current'))->toBe([false, true])
        ->and($lista[1]['device'])->toBe('Safari en iOS')
        ->and($lista[1]['ip'])->toBe('1.1.1.1')
        ->and($lista[0])->toHaveKeys(['id', 'device', 'ip', 'created_at', 'last_used_at', 'current'])
        ->and($lista[0])->not->toHaveKey('refresh_jti');
});

test('listFor sin sid actual no marca ninguna como actual y desempata por id', function () {
    $user = User::factory()->create();
    $a = sesiones()->start($user, null, null);
    $b = sesiones()->start($user, null, null);
    $ahora = now();
    UserSession::query()->update(['last_used_at' => $ahora]);

    $lista = sesiones()->listFor($user, null);

    expect(array_column($lista, 'id'))->toBe([$b->public_id, $a->public_id])
        ->and(array_column($lista, 'current'))->toBe([false, false]);
});

test('listFor limita el listado a 100 sesiones', function () {
    $user = User::factory()->create();
    foreach (range(1, 101) as $_) {
        sesiones()->start($user, null, null);
    }

    expect(sesiones()->listFor($user, null))->toHaveCount(100);
});

test('revoke marca la sesión con su motivo y olvida la caché de activa', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    expect(sesiones()->isActive($s->public_id))->toBeTrue();
    expect(Cache::get('user_session:active:'.$s->public_id))->toBeTrue();

    sesiones()->revoke($user, $s->public_id, 'soporte');

    $s->refresh();
    expect($s->revoked_at)->not->toBeNull()
        ->and($s->revoked_reason)->toBe('soporte')
        ->and(Cache::has('user_session:active:'.$s->public_id))->toBeFalse()
        ->and(sesiones()->isActive($s->public_id))->toBeFalse();
});

test('revoke usa el motivo user por defecto', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);

    sesiones()->revoke($user, $s->public_id);

    expect($s->refresh()->revoked_reason)->toBe('user');
});

test('revoke de una sesión ajena, inexistente o ya revocada lanza ModelNotFound y no toca nada', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $ajena = sesiones()->start($otro, null, null);
    $propia = sesiones()->start($user, null, null);

    expect(fn () => sesiones()->revoke($user, $ajena->public_id))->toThrow(ModelNotFoundException::class);
    expect($ajena->refresh()->revoked_at)->toBeNull();

    expect(fn () => sesiones()->revoke($user, (string) Str::uuid()))->toThrow(ModelNotFoundException::class);

    sesiones()->revoke($user, $propia->public_id);
    $primera = $propia->refresh()->revoked_at;
    $this->travel(5)->seconds();
    expect(fn () => sesiones()->revoke($user, $propia->public_id, 'otro'))->toThrow(ModelNotFoundException::class);
    expect($propia->refresh()->revoked_reason)->toBe('user')
        ->and($propia->revoked_at->equalTo($primera))->toBeTrue();
});

test('revokeOthers revoca todas menos la indicada, devuelve cuántas y no toca a otros usuarios', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $actual = sesiones()->start($user, null, null);
    $a = sesiones()->start($user, null, null);
    $b = sesiones()->start($user, null, null);
    $ajena = sesiones()->start($otro, null, null);
    sesiones()->isActive($a->public_id);

    expect(sesiones()->revokeOthers($user, $actual->public_id, 'cambio'))->toBe(2);

    expect($actual->refresh()->revoked_at)->toBeNull()
        ->and($a->refresh()->revoked_reason)->toBe('cambio')
        ->and($b->refresh()->revoked_at)->not->toBeNull()
        ->and($ajena->refresh()->revoked_at)->toBeNull()
        ->and(Cache::has('user_session:active:'.$a->public_id))->toBeFalse();

    expect(sesiones()->revokeOthers($user, $actual->public_id))->toBe(0);
});

test('revokeOthers sin sesión a salvar revoca todas con el motivo user por defecto', function () {
    $user = User::factory()->create();
    $a = sesiones()->start($user, null, null);
    sesiones()->start($user, null, null);

    expect(sesiones()->revokeOthers($user, null))->toBe(2);
    expect($a->refresh()->revoked_reason)->toBe('user');
});

test('revokeBySid revoca solo esa sesión con el motivo logout por defecto y es idempotente', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $otra = sesiones()->start($user, null, null);

    sesiones()->revokeBySid($s->public_id);
    $primera = $s->refresh()->revoked_at;
    expect($s->revoked_reason)->toBe('logout')
        ->and($otra->refresh()->revoked_at)->toBeNull();

    $this->travel(5)->seconds();
    sesiones()->revokeBySid($s->public_id, 'otro');
    expect($s->refresh()->revoked_reason)->toBe('logout')
        ->and($s->revoked_at->equalTo($primera))->toBeTrue();
});

test('isActive cachea solo el sí con el TTL configurado y nunca el no', function () {
    config(['auth.sessions.cache_ttl' => 30]);
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);

    $desconocida = (string) Str::uuid();
    expect(sesiones()->isActive($desconocida))->toBeFalse()
        ->and(Cache::has('user_session:active:'.$desconocida))->toBeFalse();

    expect(sesiones()->isActive($s->public_id))->toBeTrue();
    expect(Cache::get('user_session:active:'.$s->public_id))->toBeTrue();

    // Revocada a mano en BD (sin olvidar la caché): sigue "activa" hasta que caduca el TTL.
    UserSession::query()->update(['revoked_at' => now()]);
    expect(sesiones()->isActive($s->public_id))->toBeTrue();

    $this->travel(31)->seconds();
    Cache::forget('user_session:active:'.$s->public_id);
    expect(sesiones()->isActive($s->public_id))->toBeFalse();
});

test('touch actualiza last_used_at una vez por intervalo y no toca sesiones revocadas', function () {
    config(['auth.sessions.touch_interval' => 60]);
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $s->forceFill(['last_used_at' => now()->subDay()])->save();

    sesiones()->touch($s->public_id);
    $t1 = $s->refresh()->last_used_at;
    expect($t1->isToday())->toBeTrue();

    $s->forceFill(['last_used_at' => now()->subDay()])->save();
    sesiones()->touch($s->public_id);
    expect($s->refresh()->last_used_at->isToday())->toBeFalse();

    $r = sesiones()->start($user, null, null);
    sesiones()->revokeBySid($r->public_id);
    $r->forceFill(['last_used_at' => now()->subDay()])->save();
    sesiones()->touch($r->public_id);
    expect($r->refresh()->last_used_at->isToday())->toBeFalse();
});

test('touch con intervalo 0 o negativo no actualiza nunca', function (int $intervalo) {
    config(['auth.sessions.touch_interval' => $intervalo]);
    $s = sesiones()->start(User::factory()->create(), null, null);
    $s->forceFill(['last_used_at' => now()->subDay()])->save();

    sesiones()->touch($s->public_id);

    expect($s->refresh()->last_used_at->isToday())->toBeFalse();
})->with([0, -5]);

test('rotate con el jti vigente rota, guarda el anterior y devuelve ROTATED', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $viejo = $s->refresh()->refresh_jti;

    $r = sesiones()->rotate($s->public_id, $user->id, $viejo, 'nuevo-jti');

    $s->refresh();
    expect($r)->toBe(SessionServiceInterface::ROTATED)
        ->and($s->refresh_jti)->toBe('nuevo-jti')
        ->and($s->previous_refresh_jti)->toBe($viejo)
        ->and($s->refresh_rotated_at)->not->toBeNull()
        ->and($s->revoked_at)->toBeNull();
});

test('rotate rechaza un jti inventado sin revocar cuando no es el anterior', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);

    // Con un jti desconocido (no es el previous) se trata como reutilización: sesión revocada.
    expect(sesiones()->rotate($s->public_id, $user->id, 'inventado', 'x'))->toBe(SessionServiceInterface::REJECTED);
    expect($s->refresh()->revoked_reason)->toBe('refresh_reuse');
});

test('rotate con el anterior dentro de la gracia devuelve SUPERSEDED y deja la sesión intacta', function () {
    config(['auth.sessions.refresh_reuse_grace_seconds' => 10]);
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $j0 = $s->refresh()->refresh_jti;
    sesiones()->rotate($s->public_id, $user->id, $j0, 'j1');

    $this->travel(9)->seconds();

    expect(sesiones()->rotate($s->public_id, $user->id, $j0, 'j2'))->toBe(SessionServiceInterface::SUPERSEDED);
    $s->refresh();
    expect($s->revoked_at)->toBeNull()->and($s->refresh_jti)->toBe('j1');
});

test('rotate con el anterior pasada la gracia (y justo en ella) revoca por reutilización', function () {
    config(['auth.sessions.refresh_reuse_grace_seconds' => 10]);
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $j0 = $s->refresh()->refresh_jti;
    sesiones()->rotate($s->public_id, $user->id, $j0, 'j1');

    $this->travel(10)->seconds();

    expect(sesiones()->rotate($s->public_id, $user->id, $j0, 'j2'))->toBe(SessionServiceInterface::REJECTED);
    expect($s->refresh()->revoked_reason)->toBe('refresh_reuse');
});

test('rotate con gracia 0 o negativa revoca al instante al reutilizar el anterior', function (int $gracia) {
    config(['auth.sessions.refresh_reuse_grace_seconds' => $gracia]);
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $j0 = $s->refresh()->refresh_jti;
    sesiones()->rotate($s->public_id, $user->id, $j0, 'j1');

    expect(sesiones()->rotate($s->public_id, $user->id, $j0, 'j2'))->toBe(SessionServiceInterface::REJECTED);
    expect($s->refresh()->revoked_at)->not->toBeNull();
})->with([0, -1]);

test('rotate de una sesión sin rotación previa con un jti ajeno revoca; con un jti de dos rotaciones atrás también', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $j0 = $s->refresh()->refresh_jti;
    sesiones()->rotate($s->public_id, $user->id, $j0, 'j1');
    sesiones()->rotate($s->public_id, $user->id, 'j1', 'j2');

    expect(sesiones()->rotate($s->public_id, $user->id, $j0, 'j3'))->toBe(SessionServiceInterface::REJECTED);
    expect($s->refresh()->revoked_reason)->toBe('refresh_reuse');
});

test('rotate rechaza sesión inexistente, de otro usuario o ya revocada sin tocar nada', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $j0 = $s->refresh()->refresh_jti;

    expect(sesiones()->rotate((string) Str::uuid(), $user->id, $j0, 'x'))->toBe(SessionServiceInterface::REJECTED);

    // Otro usuario con el jti correcto: no rota y tampoco revoca la sesión ajena.
    expect(sesiones()->rotate($s->public_id, $otro->id, $j0, 'x'))->toBe(SessionServiceInterface::REJECTED);
    $s->refresh();
    expect($s->revoked_at)->toBeNull()->and($s->refresh_jti)->toBe($j0);

    sesiones()->revokeBySid($s->public_id, 'logout');
    expect(sesiones()->rotate($s->public_id, $user->id, $j0, 'x'))->toBe(SessionServiceInterface::REJECTED);
    $s->refresh();
    expect($s->revoked_reason)->toBe('logout')->and($s->refresh_jti)->toBe($j0);
});

test('start guarda los primeros 255 caracteres exactos del user agent', function () {
    $ua = 'Z'.str_repeat('b', 298).'FIN';

    $s = sesiones()->start(User::factory()->create(), null, $ua);

    expect($s->user_agent)->toBe(substr($ua, 0, 255))->and($s->user_agent)->toStartWith('Zb');
});

test('listFor devuelve last_used_at nulo si la sesión nunca se usó', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    UserSession::query()->where('id', $s->id)->update(['last_used_at' => null]);

    $fila = sesiones()->listFor($user, null)[0];

    expect($fila['last_used_at'])->toBeNull()->and($fila['created_at'])->toBeString();
});

test('revoke registra en el canal security quién, qué sesión y por qué', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $logs = capturarLogs();

    sesiones()->revoke($user, $s->public_id, 'soporte');

    expect($logs)->toHaveCount(1)
        ->and($logs[0][0])->toBe('security')
        ->and($logs[0][1])->toBe('info')
        ->and($logs[0][2])->toBe('Sesión revocada')
        ->and($logs[0][3])->toMatchArray(['user_id' => $user->id, 'session' => $s->public_id, 'reason' => 'soporte']);
});

test('revokeOthers registra cuántas revocó y no registra nada si no había ninguna', function () {
    $user = User::factory()->create();
    $actual = sesiones()->start($user, null, null);
    sesiones()->start($user, null, null);
    $logs = capturarLogs();

    sesiones()->revokeOthers($user, $actual->public_id, 'cambio');

    expect($logs)->toHaveCount(1)
        ->and($logs[0][0])->toBe('security')
        ->and($logs[0][2])->toBe('Sesiones revocadas')
        ->and($logs[0][3])->toMatchArray(['user_id' => $user->id, 'count' => 1, 'reason' => 'cambio']);

    sesiones()->revokeOthers($user, $actual->public_id);
    expect($logs)->toHaveCount(1);
});

test('touch deja la marca de intervalo en caché con la clave de la sesión', function () {
    config(['auth.sessions.touch_interval' => 60]);
    $s = sesiones()->start(User::factory()->create(), null, null);

    expect(Cache::has('user_session:touch:'.$s->public_id))->toBeFalse();
    sesiones()->touch($s->public_id);
    expect(Cache::has('user_session:touch:'.$s->public_id))->toBeTrue();
});

test('rotate actualiza last_used_at y updated_at de la sesión', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $j0 = $s->refresh()->refresh_jti;
    UserSession::query()->where('id', $s->id)->update(['last_used_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

    sesiones()->rotate($s->public_id, $user->id, $j0, 'j1');

    $s->refresh();
    expect($s->last_used_at->isToday())->toBeTrue()->and($s->updated_at->isToday())->toBeTrue();
});

test('rotate con gracia de 1 segundo ya tolera la carrera del refresh anterior', function () {
    config(['auth.sessions.refresh_reuse_grace_seconds' => 1]);
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $j0 = $s->refresh()->refresh_jti;
    sesiones()->rotate($s->public_id, $user->id, $j0, 'j1');

    expect(sesiones()->rotate($s->public_id, $user->id, $j0, 'j2'))->toBe(SessionServiceInterface::SUPERSEDED);
});

test('rotate registra la carrera tolerada en auth y la reutilización en security', function () {
    $user = User::factory()->create();
    $s = sesiones()->start($user, null, null);
    $j0 = $s->refresh()->refresh_jti;
    sesiones()->rotate($s->public_id, $user->id, $j0, 'j1');
    $logs = capturarLogs();

    sesiones()->rotate($s->public_id, $user->id, $j0, 'j2');

    expect($logs)->toHaveCount(1)
        ->and($logs[0][0])->toBe('auth')
        ->and($logs[0][1])->toBe('info')
        ->and($logs[0][3])->toMatchArray(['user_id' => $user->id, 'session' => $s->public_id]);

    config(['auth.sessions.refresh_reuse_grace_seconds' => 0]);
    $logs->exchangeArray([]);
    sesiones()->rotate($s->public_id, $user->id, $j0, 'j3');

    expect($logs)->toHaveCount(1)
        ->and($logs[0][0])->toBe('security')
        ->and($logs[0][1])->toBe('warning')
        ->and($logs[0][3])->toMatchArray(['user_id' => $user->id, 'session' => $s->public_id]);
});
