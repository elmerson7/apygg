<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Inventario de rutas POST/PATCH SIN `idempotent` y por qué (docs/idempotency.md, «Qué rutas la llevan»).
 * Una ruta nueva que cree un recurso, cobre o dispare un efecto externo debe llevar el middleware; si de verdad
 * no aplica, se añade aquí con su motivo. Las rutas de módulos opcionales pueden no estar registradas.
 *
 * @return array<string, string> "MÉTODO uri" => motivo
 */
function idemExcepciones(): array
{
    $auth = 'Autenticación: sin sesión previa no hay actor estable; login/refresh/verify no crean nada y los reintentos se frenan con throttle';

    return [
        'POST auth/login' => $auth,
        'POST auth/register' => 'Alta pública sin actor: el email único ya impide el duplicado (422)',
        'POST auth/refresh' => $auth.'; el refresh rota el token y su reutilización se detecta aparte (docs/sessions-and-2fa.md)',
        'POST auth/logout' => 'Naturalmente idempotente: cerrar sesión dos veces deja lo mismo',
        'POST auth/forgot-password' => 'Sin sesión; respuesta uniforme anti-enumeración y límite por destino/IP: repetir una respuesta guardada por IP no aporta',
        'POST auth/reset-password' => 'El token de reinicio es de un solo uso: la segunda llamada falla por diseño',
        'POST auth/change-password' => 'Estado final idéntico al repetir y rota las sesiones: repetir la respuesta guardada ocultaría el cambio',
        'POST auth/2fa/verify' => $auth.' (código TOTP de un solo uso)',
        'POST auth/email/verify' => 'El token de verificación es de un solo uso y repetirlo es inocuo',
        'POST auth/otp/request' => 'Sin sesión: anti-enumeración y límites por destino e IP en el servicio (docs/otp-login.md)', // module:otp-login
        'POST auth/otp/verify' => $auth.' (código de un solo uso)', // module:otp-login
        'POST auth/social/{provider}' => $auth.' (canje de id_token/access_token)', // module:social-login
        'POST user/2fa/setup' => 'Seguridad de la cuenta: cada llamada regenera el secreto pendiente; guardar el secreto en caché de respuestas no aporta',
        'POST user/2fa/confirm' => 'Código TOTP de un solo uso',
        'POST user/2fa/recovery-codes' => 'Regenera los códigos a propósito: repetir una respuesta con códigos antiguos sería peligroso',
        'POST broadcasting/auth' => 'Firma de canal de Reverb: sin efectos', // module:reverb
        'POST admin/impersonate/{id}' => 'Emite un token corto con motivo y auditoría: cada llamada debe ser una sesión nueva auditada (docs/impersonation.md)', // module:impersonation
        'POST admin/impersonate/stop' => 'Naturalmente idempotente', // module:impersonation
        'POST admin/maintenance' => 'Interruptor de estado: repetir deja el mismo estado', // module:maintenance
        'POST admin/mail/templates/seed-defaults' => 'Siembra con upsert: repetir no duplica',
        'POST admin/mail/templates/{mailTemplate}/toggle' => 'Interruptor explícito (pausa/activa): estado final idéntico',
        'POST admin/security/scans/{securityScan}/stop' => 'Naturalmente idempotente (parar lo ya parado)',
        'POST users/{id}/restore' => 'Naturalmente idempotente (restaurar lo restaurado)',
        'POST users/{id}/roles' => 'Asignación de roles: estado final idéntico al repetir',
        'PATCH tenant/members/{userId}' => 'Cambio de rol absoluto: estado final idéntico al repetir',
        'POST tenant/members/{userId}/suspend' => 'Transición de estado idempotente',
        'POST tenant/members/{userId}/reactivate' => 'Transición de estado idempotente',
        'POST tenant/invitations/accept' => 'El token de la invitación es de un solo uso y se canjea una vez (docs/tenant-onboarding.md)',
        'POST tenant/billing/subscription/cancel' => 'Transición de estado: cancelar lo ya cancelado no cobra ni crea nada (409/estado igual); sin dinero en juego', // module:billing
        'POST tenant/billing/subscription/resume' => 'Transición de estado sin cobro: repetir deja el mismo estado', // module:billing
        'POST tenant/billing/subscription/preview-change' => 'Solo calcula (no escribe ni cobra)', // module:billing
        'POST user/notifications/read-all' => 'Marcar como leído es naturalmente idempotente',
        'POST user/notifications/{id}/read' => 'Marcar como leído es naturalmente idempotente',
        'POST files/uploads/{uuid}/complete' => 'Completar una subida es idempotente por diseño (reclamo atómico del upload, docs/files.md)',
        'POST files/uploads/{uuid}/parts' => 'Firma URLs de partes: repetir devuelve URLs equivalentes; las partes se identifican por número',
        'POST webhooks/inbound/{provider}' => 'Webhook entrante con su propia deduplicación (recibos idempotentes por proveedor, docs/integrations.md)',
        'POST webhooks/mail' => 'Webhook entrante con su propia deduplicación (recibos idempotentes)',
        'POST resend/webhook' => 'Webhook entrante de Resend: deduplicación propia por id de evento',
        'POST sync/batch' => 'Idempotencia propia por operación dentro del lote (docs/offline-sync.md)', // module:offline-sync
        'POST test/sentry/logs' => 'Diagnóstico solo fuera de producción',
        'POST test/sentry/exception' => 'Diagnóstico solo fuera de producción',
    ];
}

/**
 * Rutas POST/PATCH propias de la app (se ignoran los paquetes: Telescope, Horizon, Livewire, Filament).
 *
 * @return array<string, string|null> "MÉTODO uri" => middleware `idempotent[:required]` o null
 */
function idemRutasEscritura(): array
{
    $ajenas = ['telescope', 'horizon', 'backoffice', '_ignition'];
    $rutas = [];

    foreach (Route::getRoutes()->getRoutes() as $ruta) {
        foreach (array_intersect($ruta->methods(), ['POST', 'PATCH']) as $metodo) {
            $uri = ltrim($ruta->uri(), '/');
            $prefijo = strtok($uri, '/');
            if (in_array($prefijo, $ajenas, true) || str_starts_with((string) $prefijo, 'livewire')) {
                continue;
            }
            $uri = preg_replace('#^'.preg_quote((string) config('api.prefix', ''), '#').'/#', '', $uri, 1) ?: $uri;
            $mw = array_map('strval', $ruta->gatherMiddleware());
            $rutas["$metodo $uri"] = collect($mw)->first(fn ($m) => str_starts_with($m, 'idempotent'));
        }
    }

    return $rutas;
}

test('todo POST/PATCH de la app lleva idempotent o está en la lista de excepciones con su motivo', function () {
    $sinMiddleware = collect(idemRutasEscritura())->filter(fn ($mw) => $mw === null)->keys()->all();
    $sinJustificar = array_values(array_diff($sinMiddleware, array_keys(idemExcepciones())));

    expect($sinJustificar)->toBe([], 'Rutas POST/PATCH sin `idempotent` ni excepción justificada en idemExcepciones(): '.implode(', ', $sinJustificar));
});

test('la lista de excepciones no tiene rutas que ya llevan idempotent ni rutas inexistentes de la base', function () {
    $rutas = idemRutasEscritura();
    $conMiddleware = array_values(array_filter(array_keys(idemExcepciones()), fn ($k) => ($rutas[$k] ?? null) !== null));

    // Módulos opcionales o diagnóstico que pueden no existir en un derivado/entorno.
    $opcionales = ['POST auth/otp/request', 'POST auth/otp/verify', 'POST auth/social/{provider}', 'POST sync/batch', 'POST test/sentry/logs', 'POST test/sentry/exception', 'POST broadcasting/auth', 'POST resend/webhook', 'POST webhooks/mail', 'POST webhooks/inbound/{provider}', 'POST files/uploads/{uuid}/complete', 'POST files/uploads/{uuid}/parts', 'POST admin/impersonate/{id}', 'POST admin/impersonate/stop'];
    $inexistentes = array_values(array_diff(array_keys(idemExcepciones()), array_keys($rutas), $opcionales));

    expect($conMiddleware)->toBe([], 'Excepciones que ya llevan idempotent: '.implode(', ', $conMiddleware))
        ->and($inexistentes)->toBe([], 'Excepciones de rutas que no existen: '.implode(', ', $inexistentes));
});
