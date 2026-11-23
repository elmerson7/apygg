<?php

use Illuminate\Http\Client\Response;

/*
| Recorrido E2E "facturación" por HTTP real, con la pasarela simulada (PAYMENTS_MODE=fake):
|   plan con prueba -> renovación con cobro -> segunda renovación -> impago (past_due, gracia con aviso)
|   -> bloqueo 402 -> pago de la factura -> desbloqueo -> baja inmediata -> recontratar,
| con avisos en la bandeja, PDF de la factura, idempotencia de las operaciones de dinero y aislamiento A/B.
|
| El reloj se adelanta con e2eViajar() (fechas de la empresa en la BD del stack) y el cron se lanza a mano
| con e2eArtisan('billing:renew'): hace falta el runner del stack temporal (make e2e). En un stack remoto
| los pasos que lo necesitan se omiten. Docs: docs/billing.md y docs/testing.md.
*/

const E2E_FA = 'facturacion';

beforeEach(fn () => e2eRequiereMultiempresa());

/** Una escritura cualquiera de la empresa (invitar a alguien con email único): sirve para probar el bloqueo. */
function e2eEscritura(string $token, string $slug): Response
{
    static $n = 0;
    $n++;

    return e2eApi($token, $slug)->post('/tenant/invitations', ['email' => 'e2e-fa-'.$n.'-'.e2e(E2E_FA)['run'].'@example.test']);
}

test('el catalogo publico lista los planes publicos y oculta los privados', function () {
    $res = e2eApi()->get('/plans');
    $codigos = collect(e2eItems($res))->pluck('code')->all();

    expect($res->status())->toBe(200)
        ->and($codigos)->toContain('free', 'pro')
        ->and($codigos)->not->toContain('enterprise');
});

test('una empresa nueva y otra ajena parten sin suscripcion y con escritura permitida', function () {
    $a = e2eUsuario(E2E_FA, 'dueno-a');
    $b = e2eUsuario(E2E_FA, 'dueno-b');
    $s = e2e(E2E_FA);
    $s['a'] = $a + ['empresa' => e2eEmpresa($a['access'], E2E_FA, 'a')];
    $s['b'] = $b + ['empresa' => e2eEmpresa($b['access'], E2E_FA, 'b')];

    $sub = e2eApi($a['access'], $s['a']['empresa']['slug'])->get('/tenant/billing/subscription');
    expect($sub->status())->toBeIn([200, 404])->and($sub->json('data.status'))->toBeNull();

    // Sin suscripción rige el plan gratuito (BILLING_NO_SUBSCRIPTION=allow): se puede escribir.
    expect(e2eEscritura($a['access'], $s['a']['empresa']['slug'])->status())->toBe(201);
})->depends('el catalogo publico lista los planes publicos y oculta los privados');

test('contratar el plan pro empieza una prueba sin cobrar y el reintento con la misma clave no duplica', function () {
    $s = e2e(E2E_FA);
    $slug = $s['a']['empresa']['slug'];
    $clave = 'sub-'.$s['run'];
    $cuerpo = ['plan' => 'pro', 'interval' => 'month', 'currency' => 'USD'];

    $alta = e2eApi($s['a']['access'], $slug)->withHeaders(['Idempotency-Key' => $clave])->post('/tenant/billing/subscription', $cuerpo);
    expect($alta->status())->toBe(201, $alta->body())
        ->and($alta->json('data.status'))->toBe('trialing')
        ->and($alta->json('data.plan_code'))->toBe('pro')
        ->and($alta->json('data.unit_amount_minor'))->toBe(2900);

    $repetida = e2eApi($s['a']['access'], $slug)->withHeaders(['Idempotency-Key' => $clave])->post('/tenant/billing/subscription', $cuerpo);
    expect($repetida->header('Idempotent-Replayed'))->toBe('true')
        ->and($repetida->json('data.id'))->toBe($alta->json('data.id'));

    // Sin clave la ruta de cobro se rechaza (idempotent:required); con otra clave, ya hay una suscripción viva (una sola por empresa).
    expect(e2eApi($s['a']['access'], $slug)->post('/tenant/billing/subscription', $cuerpo)->json('code'))->toBe('idempotency_key_required');
    $otra = e2eApi($s['a']['access'], $slug)->withHeaders(['Idempotency-Key' => 'otra-'.$s['run']])->post('/tenant/billing/subscription', $cuerpo);
    expect($otra->status())->toBe(409)->and($otra->json('code'))->toBe('subscription_exists');

    // Pagar o contratar es cosa de dueños/admins: un tercero con otra empresa ni siquiera entra.
    expect(e2eApi($s['b']['access'], $slug)->get('/tenant/billing/subscription')->status())->toBe(403);

    $s['sub_id'] = $alta->json('data.id');
    expect(e2eApi($s['a']['access'], $slug)->get('/tenant/billing/invoices')->status())->toBe(200)
        ->and(e2eItems(e2eApi($s['a']['access'], $slug)->get('/tenant/billing/invoices')))->toBeEmpty('la prueba no genera factura');
})->depends('una empresa nueva y otra ajena parten sin suscripcion y con escritura permitida');

test('se guarda un metodo de pago sin datos de tarjeta', function () {
    $s = e2e(E2E_FA);
    $res = e2eApi($s['a']['access'], $s['a']['empresa']['slug'])->put('/tenant/billing/payment-method', ['token' => 'tok_visa']);

    expect($res->status())->toBe(200, $res->body());
    $lectura = e2eApi($s['a']['access'], $s['a']['empresa']['slug'])->get('/tenant/billing/payment-method');
    expect($lectura->status())->toBe(200)
        ->and(json_encode($lectura->json()))->not->toContain('tok_visa');
})->depends('contratar el plan pro empieza una prueba sin cobrar y el reintento con la misma clave no duplica');

test('al terminar la prueba el cron cobra la primera factura y la suscripcion queda activa', function () {
    e2eRequiereStackLocal();
    $s = e2e(E2E_FA);
    $slug = $s['a']['empresa']['slug'];

    e2eViajar($s['a']['empresa']['id'], 15);
    e2eArtisan('billing:renew');

    $sub = e2eApi($s['a']['access'], $slug)->get('/tenant/billing/subscription');
    expect($sub->json('data.status'))->toBe('active');

    $facturas = e2eItems(e2eApi($s['a']['access'], $slug)->get('/tenant/billing/invoices'));
    expect($facturas)->toHaveCount(1)
        ->and($facturas[0]['status'])->toBe('paid')
        ->and($facturas[0]['total_minor'])->toBe(2900)
        ->and($facturas[0]['number'])->toMatch('/^F-\d{4}-\d{6}$/');

    $s['factura_1'] = $facturas[0]['id'];
    $s['numero_1'] = $facturas[0]['number'];

    // PDF real de la factura.
    $pdf = e2eApi($s['a']['access'], $slug)->get('/tenant/billing/invoices/'.$s['factura_1'].'/pdf');
    expect($pdf->status())->toBe(200)
        ->and($pdf->header('Content-Type'))->toContain('application/pdf')
        ->and(substr($pdf->body(), 0, 4))->toBe('%PDF');
})->depends('se guarda un metodo de pago sin datos de tarjeta');

test('el cobro llega a la bandeja de notificaciones del dueno', function () {
    $s = e2e(E2E_FA);
    $slug = $s['a']['empresa']['slug'];

    $aviso = e2eEsperar(function () use ($s, $slug) {
        $lista = e2eItems(e2eApi($s['a']['access'], $slug)->get('/user/notifications'));

        return collect($lista)->firstWhere('type', 'billing_invoice_paid');
    }, 45);

    expect($aviso)->not->toBeNull('el aviso de factura pagada no llegó en 45 s: ¿Horizon consume la cola?')
        ->and($aviso['read'])->toBeFalse();

    $sinLeer = e2eApi($s['a']['access'], $slug)->get('/user/notifications/unread-count');
    expect($sinLeer->status())->toBe(200)->and($sinLeer->json('data.count') ?? $sinLeer->json('data.unread'))->toBeGreaterThanOrEqual(1);

    // Marcarla como leída es idempotente y solo toca lo propio.
    expect(e2eApi($s['a']['access'], $slug)->post('/user/notifications/'.$aviso['id'].'/read')->status())->toBe(200);
    $leida = collect(e2eItems(e2eApi($s['a']['access'], $slug)->get('/user/notifications')))->firstWhere('id', $aviso['id']);
    expect($leida['read'])->toBeTrue();

    // El dueño de B no la ve (otra bandeja, otra empresa).
    $deB = e2eItems(e2eApi($s['b']['access'], $s['b']['empresa']['slug'])->get('/user/notifications'));
    expect(collect($deB)->pluck('id')->all())->not->toContain($aviso['id']);
})->depends('al terminar la prueba el cron cobra la primera factura y la suscripcion queda activa');

test('la siguiente renovacion emite otra factura con numero distinto y el cron repetido no la duplica', function () {
    e2eRequiereStackLocal();
    $s = e2e(E2E_FA);
    $slug = $s['a']['empresa']['slug'];

    e2eViajar($s['a']['empresa']['id'], 31);
    e2eArtisan('billing:renew');
    e2eArtisan('billing:renew'); // un segundo pase del cron no puede facturar otra vez

    $facturas = e2eItems(e2eApi($s['a']['access'], $slug)->get('/tenant/billing/invoices'));
    expect($facturas)->toHaveCount(2);
    $numeros = collect($facturas)->pluck('number');
    expect($numeros->unique())->toHaveCount(2)
        ->and($facturas[0]['status'])->toBe('paid')->and($facturas[1]['status'])->toBe('paid');

    expect(e2eApi($s['a']['access'], $slug)->get('/tenant/billing/subscription')->json('data.status'))->toBe('active');
})->depends('el cobro llega a la bandeja de notificaciones del dueno');

test('si el banco rechaza la renovacion la suscripcion pasa a past_due y aun se puede escribir con aviso', function () {
    e2eRequiereStackLocal();
    $s = e2e(E2E_FA);
    $slug = $s['a']['empresa']['slug'];

    expect(e2eApi($s['a']['access'], $slug)->put('/tenant/billing/payment-method', ['token' => 'tok_declined'])->status())->toBe(200);
    e2eViajar($s['a']['empresa']['id'], 31);
    e2eArtisan('billing:renew');

    $sub = e2eApi($s['a']['access'], $slug)->get('/tenant/billing/subscription');
    expect($sub->json('data.status'))->toBe('past_due');

    $abiertas = e2eItems(e2eApi($s['a']['access'], $slug)->get('/tenant/billing/invoices', ['status' => 'open']));
    expect($abiertas)->toHaveCount(1);
    $s['factura_impaga'] = $abiertas[0]['id'];

    // Dentro de la gracia se escribe, con cabecera de aviso.
    $escritura = e2eEscritura($s['a']['access'], $slug);
    expect($escritura->status())->toBe(201)
        ->and($escritura->header('X-Subscription-Warning'))->toStartWith('past_due');

    // El aviso obligatorio de pago fallido llega a la bandeja.
    $aviso = e2eEsperar(fn () => collect(e2eItems(e2eApi($s['a']['access'], $slug)->get('/user/notifications')))->firstWhere('type', 'billing_payment_failed'), 45);
    expect($aviso)->not->toBeNull('el aviso de pago fallido no llegó en 45 s');
})->depends('la siguiente renovacion emite otra factura con numero distinto y el cron repetido no la duplica');

test('pasada la gracia las escrituras dan 402 y la lectura y la facturacion siguen abiertas', function () {
    e2eRequiereStackLocal();
    $s = e2e(E2E_FA);
    $slug = $s['a']['empresa']['slug'];

    e2eViajar($s['a']['empresa']['id'], 8);

    $bloqueada = e2eEscritura($s['a']['access'], $slug);
    expect($bloqueada->status())->toBe(402)
        ->and($bloqueada->json('code'))->toBe('payment_required')
        ->and($bloqueada->json('errors.suggested_action.action') ?? $bloqueada->json('suggested_action.action'))->toBe('pay_invoice');

    // Lectura y rutas de facturación (para poder pagar) siguen permitidas.
    expect(e2eApi($s['a']['access'], $slug)->get('/tenant/members')->status())->toBe(200);
    expect(e2eApi($s['a']['access'], $slug)->get('/tenant/billing/subscription')->status())->toBe(200);
    // La otra empresa no se ve afectada.
    expect(e2eEscritura($s['b']['access'], $s['b']['empresa']['slug'])->status())->toBe(201);
})->depends('si el banco rechaza la renovacion la suscripcion pasa a past_due y aun se puede escribir con aviso');

test('cambiar de tarjeta y pagar la factura reactiva la empresa y el reintento no cobra dos veces', function () {
    $s = e2e(E2E_FA);
    $slug = $s['a']['empresa']['slug'];
    $factura = $s['factura_impaga'];

    expect(e2eApi($s['a']['access'], $slug)->put('/tenant/billing/payment-method', ['token' => 'tok_visa'])->status())->toBe(200);

    $clave = 'pago-'.$s['run'];
    $pago = e2eApi($s['a']['access'], $slug)->withHeaders(['Idempotency-Key' => $clave])->post("/tenant/billing/invoices/{$factura}/pay");
    expect($pago->status())->toBe(200, $pago->body());

    $repetido = e2eApi($s['a']['access'], $slug)->withHeaders(['Idempotency-Key' => $clave])->post("/tenant/billing/invoices/{$factura}/pay");
    expect($repetido->header('Idempotent-Replayed'))->toBe('true');

    $detalle = e2eApi($s['a']['access'], $slug)->get("/tenant/billing/invoices/{$factura}");
    $cobrados = collect($detalle->json('data.payments'))->where('status', 'succeeded');
    expect($detalle->json('data.status'))->toBe('paid')
        ->and($cobrados)->toHaveCount(1)
        ->and(collect($detalle->json('data.payments'))->where('status', 'failed')->count())->toBeGreaterThanOrEqual(1);

    expect(e2eApi($s['a']['access'], $slug)->get('/tenant/billing/subscription')->json('data.status'))->toBe('active');
    expect(e2eEscritura($s['a']['access'], $slug)->status())->toBe(201);
})->depends('pasada la gracia las escrituras dan 402 y la lectura y la facturacion siguen abiertas');

test('aislamiento A/B: la factura de una empresa no se lee desde la otra', function () {
    $s = e2e(E2E_FA);
    $factura = $s['factura_1'];

    // Con su propio tenant, el id de otra empresa no existe.
    expect(e2eApi($s['b']['access'], $s['b']['empresa']['slug'])->get("/tenant/billing/invoices/{$factura}")->status())->toBe(404);
    expect(e2eApi($s['b']['access'], $s['b']['empresa']['slug'])->get("/tenant/billing/invoices/{$factura}/pdf")->status())->toBe(404);
    expect(e2eApi($s['b']['access'], $s['b']['empresa']['slug'])->withHeaders(['Idempotency-Key' => 'ajena-'.$s['run']])->post("/tenant/billing/invoices/{$factura}/pay")->status())->toBeIn([404, 409]);
    // Declarando la empresa de A, ni entra.
    expect(e2eApi($s['b']['access'], $s['a']['empresa']['slug'])->get("/tenant/billing/invoices/{$factura}")->status())->toBe(403);
    // Y B no hereda nada de la suscripción de A.
    expect(e2eApi($s['b']['access'], $s['b']['empresa']['slug'])->get('/tenant/billing/subscription')->json('data.status'))->toBeNull();
    expect(e2eItems(e2eApi($s['b']['access'], $s['b']['empresa']['slug'])->get('/tenant/billing/invoices')))->toBeEmpty();
})->depends('cambiar de tarjeta y pagar la factura reactiva la empresa y el reintento no cobra dos veces');

test('la baja inmediata bloquea la escritura con 402 y recontratar la desbloquea', function () {
    $s = e2e(E2E_FA);
    $slug = $s['a']['empresa']['slug'];

    $baja = e2eApi($s['a']['access'], $slug)->post('/tenant/billing/subscription/cancel', ['immediately' => true]);
    expect($baja->status())->toBe(200, $baja->body());
    expect(e2eApi($s['a']['access'], $slug)->get('/tenant/billing/subscription')->json('data.status'))->toBe('canceled');

    $bloqueada = e2eEscritura($s['a']['access'], $slug);
    expect($bloqueada->status())->toBe(402)->and($bloqueada->json('code'))->toBe('subscription_inactive');
    // Los datos siguen legibles.
    expect(e2eApi($s['a']['access'], $slug)->get('/tenant/members')->status())->toBe(200);

    // Recontratar (la prueba ya se usó: cobra al momento con la tarjeta guardada).
    $nueva = e2eApi($s['a']['access'], $slug)->withHeaders(['Idempotency-Key' => 'resub-'.$s['run']])
        ->post('/tenant/billing/subscription', ['plan' => 'pro', 'interval' => 'month', 'currency' => 'USD']);
    expect($nueva->status())->toBe(201, $nueva->body())->and($nueva->json('data.status'))->toBe('active');
    expect(e2eEscritura($s['a']['access'], $slug)->status())->toBe(201);

    // Todas las facturas de la empresa tienen número único.
    $numeros = collect(e2eItems(e2eApi($s['a']['access'], $slug)->get('/tenant/billing/invoices', ['per_page' => 50])))->pluck('number');
    expect($numeros->unique()->count())->toBe($numeros->count())->and($numeros->count())->toBeGreaterThanOrEqual(4);
})->depends('aislamiento A/B: la factura de una empresa no se lee desde la otra');
