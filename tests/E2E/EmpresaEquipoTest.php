<?php

/*
| Recorrido E2E "empresa y equipo" (multiempresa por HTTP real, con correo por la cola real):
|   alta de empresa A y B -> invitación por correo (Mailpit) con Idempotency-Key -> aceptación -> miembros ->
|   aislamiento A/B (cabecera X-Tenant, miembros, invitaciones, exportación del tenant).
| Cada ejecución crea usuarios y empresas con datos únicos: es repetible sin limpiar nada.
| Necesita MULTI_TENANCY_ENABLED y TENANT_SIGNUP_ENABLED en el stack (docker-compose.e2e.yml los pone).
*/

const E2E_EQ = 'equipo';

beforeEach(fn () => e2eRequiereMultiempresa());

test('una persona da de alta su empresa y queda como dueña', function () {
    $dueno = e2eUsuario(E2E_EQ, 'dueno-a');
    $empresa = e2eEmpresa($dueno['access'], E2E_EQ, 'a');

    $mias = e2eApi($dueno['access'])->get('/tenants/mine');
    expect($mias->status())->toBe(200);
    $propia = collect($mias->json('data'))->firstWhere('slug', $empresa['slug']);
    expect($propia)->not->toBeNull()
        ->and($propia['role'])->toBe('owner')
        ->and($propia['status'])->toBe('active');

    // El slug es único: otra persona no puede reutilizarlo.
    $otra = e2eUsuario(E2E_EQ, 'intruso');
    $repetido = e2eApi($otra['access'])->post('/tenants', ['name' => 'Copia', 'slug' => $empresa['slug']]);
    expect($repetido->status())->toBe(422);

    e2e(E2E_EQ)['dueno_a'] = $dueno;
    e2e(E2E_EQ)['empresa_a'] = $empresa;
    e2e(E2E_EQ)['intruso'] = $otra;
});

test('la empresa B de otra persona nace independiente de la A', function () {
    $dueno = e2eUsuario(E2E_EQ, 'dueno-b');
    e2e(E2E_EQ)['dueno_b'] = $dueno;
    e2e(E2E_EQ)['empresa_b'] = e2eEmpresa($dueno['access'], E2E_EQ, 'b');

    expect(e2e(E2E_EQ)['empresa_b']['id'])->not->toBe(e2e(E2E_EQ)['empresa_a']['id']);
})->depends('una persona da de alta su empresa y queda como dueña');

test('invitar sin ser miembro de la empresa se rechaza', function () {
    $a = e2e(E2E_EQ)['empresa_a'];

    // Un intruso que declara la empresa A recibe 403; sin cabecera, 400.
    $intruso = e2e(E2E_EQ)['intruso'];
    $res = e2eApi($intruso['access'], $a['slug'])->post('/tenant/invitations', ['email' => 'x-'.e2e(E2E_EQ)['run'].'@example.test']);
    expect($res->status())->toBe(403);

    $sinTenant = e2eApi(e2e(E2E_EQ)['dueno_a']['access'])->post('/tenant/invitations', ['email' => 'x@example.test']);
    expect($sinTenant->status())->toBe(400);
})->depends('la empresa B de otra persona nace independiente de la A');

test('invitar con Idempotency-Key crea una sola invitación y el reintento devuelve la misma', function () {
    $a = e2e(E2E_EQ)['empresa_a'];
    $dueno = e2e(E2E_EQ)['dueno_a'];
    $email = 'e2e-invitado-'.e2e(E2E_EQ)['run'].'@example.test';
    $clave = 'inv-'.e2e(E2E_EQ)['run'];
    e2e(E2E_EQ)['email_invitado'] = $email;

    $primera = e2eApi($dueno['access'], $a['slug'])->withHeaders(['Idempotency-Key' => $clave])
        ->post('/tenant/invitations', ['email' => $email, 'role' => 'member']);
    expect($primera->status())->toBe(201)
        ->and($primera->header('Idempotent-Replayed'))->toBe('')
        ->and($primera->json('data.status'))->toBe('pending');

    $repetida = e2eApi($dueno['access'], $a['slug'])->withHeaders(['Idempotency-Key' => $clave])
        ->post('/tenant/invitations', ['email' => $email, 'role' => 'member']);
    expect($repetida->status())->toBe(201)
        ->and($repetida->header('Idempotent-Replayed'))->toBe('true')
        ->and($repetida->json('data.id'))->toBe($primera->json('data.id'));

    // La misma clave con otro cuerpo es un error del cliente.
    $distinta = e2eApi($dueno['access'], $a['slug'])->withHeaders(['Idempotency-Key' => $clave])
        ->post('/tenant/invitations', ['email' => 'otro-'.$email, 'role' => 'member']);
    expect($distinta->status())->toBe(422);

    // Y en la empresa solo hay UNA invitación (el reintento no la duplicó).
    $lista = e2eApi($dueno['access'], $a['slug'])->get('/tenant/invitations', ['status' => 'pending']);
    expect($lista->status())->toBe(200)
        ->and(collect($lista->json('data'))->where('email', $email))->toHaveCount(1);
})->depends('invitar sin ser miembro de la empresa se rechaza');

test('el correo de invitación llega a Mailpit por la cola y trae un token que no se guarda en claro', function () {
    $texto = e2eCorreo(e2e(E2E_EQ)['email_invitado']);

    expect($texto)->not->toBeNull('la invitación no llegó en 40 s: ¿Horizon consume la cola default?');
    expect(preg_match('/token=([A-Za-z0-9]{64})/', (string) $texto, $m))->toBe(1, 'el correo no lleva el enlace con el token');
    e2e(E2E_EQ)['token_invitacion'] = $m[1];
})->depends('invitar con Idempotency-Key crea una sola invitación y el reintento devuelve la misma');

test('quien no es la persona invitada no puede aceptar con el token', function () {
    $intruso = e2e(E2E_EQ)['intruso'];

    $res = e2eApi($intruso['access'])->post('/tenant/invitations/accept', ['token' => e2e(E2E_EQ)['token_invitacion']]);
    expect($res->status())->toBe(403)
        ->and($res->json('code'))->toBe('invitation_email_mismatch');

    $falso = e2eApi($intruso['access'])->post('/tenant/invitations/accept', ['token' => str_repeat('a', 64)]);
    expect($falso->status())->toBe(404);
})->depends('el correo de invitación llega a Mailpit por la cola y trae un token que no se guarda en claro');

test('la persona invitada se registra, acepta y entra como miembro', function () {
    $email = e2e(E2E_EQ)['email_invitado'];
    $clave = e2e(E2E_EQ)['password'];

    $registro = e2ePostAuth('/auth/register', ['name' => 'Invitada', 'email' => $email, 'password' => $clave, 'password_confirmation' => $clave]);
    expect($registro->status())->toBe(201);
    $token = (string) $registro->json('data.access_token');
    e2e(E2E_EQ)['invitado'] = ['access' => $token, 'email' => $email, 'id' => (int) $registro->json('data.user.id')];

    $acepta = e2eApi($token)->post('/tenant/invitations/accept', ['token' => e2e(E2E_EQ)['token_invitacion']]);
    expect($acepta->status())->toBe(200, $acepta->body());

    // Aceptar otra vez: la invitación ya está usada.
    expect(e2eApi($token)->post('/tenant/invitations/accept', ['token' => e2e(E2E_EQ)['token_invitacion']])->status())->toBe(409);

    // Ya es miembro de A con el rol invitado.
    $mias = e2eApi($token)->get('/tenants/mine');
    $a = collect($mias->json('data'))->firstWhere('slug', e2e(E2E_EQ)['empresa_a']['slug']);
    expect($a)->not->toBeNull()->and($a['role'])->toBe('member');

    // El dueño la ve en el listado de miembros.
    $miembros = e2eApi(e2e(E2E_EQ)['dueno_a']['access'], e2e(E2E_EQ)['empresa_a']['slug'])->get('/tenant/members');
    expect($miembros->status())->toBe(200)
        ->and(collect($miembros->json('data'))->pluck('email')->all())->toContain($email);
})->depends('quien no es la persona invitada no puede aceptar con el token');

test('un miembro raso entra a la empresa y lee, pero no gestiona miembros ni invitaciones', function () {
    $invitado = e2e(E2E_EQ)['invitado'];
    $dueno = e2e(E2E_EQ)['dueno_a'];
    $slug = e2e(E2E_EQ)['empresa_a']['slug'];

    // Entra al tenant: su bandeja y el listado de miembros ...
    expect(e2eApi($invitado['access'], $slug)->get('/user/notifications/unread-count')->status())->toBe(200);
    expect(e2eApi($invitado['access'], $slug)->get('/tenant/members')->status())->toBe(200);
    // ... pero no puede invitar, ni tocar a otros miembros, ni expulsar al dueño.
    expect(e2eApi($invitado['access'], $slug)->post('/tenant/invitations', ['email' => 'z-'.e2e(E2E_EQ)['run'].'@example.test'])->status())->toBe(403);
    expect(e2eApi($invitado['access'], $slug)->patch('/tenant/members/'.$dueno['id'], ['role' => 'member'])->status())->toBe(403);
    expect(e2eApi($invitado['access'], $slug)->delete('/tenant/members/'.$dueno['id'])->status())->toBe(403);

    // El dueño sigue siéndolo.
    $miembros = e2eItems(e2eApi($dueno['access'], $slug)->get('/tenant/members'));
    expect(collect($miembros)->firstWhere('email', $dueno['email'])['role'])->toBe('owner');
})->depends('la persona invitada se registra, acepta y entra como miembro');

test('aislamiento A/B: ninguna de las dos empresas ve ni toca lo de la otra', function () {
    $s = e2e(E2E_EQ);
    $a = $s['empresa_a'];
    $b = $s['empresa_b'];

    // El dueño de B no entra en A, ni por slug ni por id.
    foreach ([$a['slug'], $a['id']] as $referencia) {
        expect(e2eApi($s['dueno_b']['access'], $referencia)->get('/tenant/members')->status())->toBe(403);
    }
    // Y la persona invitada a A (no a B) tampoco entra en B.
    expect(e2eApi($s['invitado']['access'], $b['slug'])->get('/user/notifications/unread-count')->status())->toBe(403);

    // El listado de miembros de B no contiene a nadie de A.
    $miembrosB = e2eApi($s['dueno_b']['access'], $b['slug'])->get('/tenant/members');
    expect($miembrosB->status())->toBe(200)
        ->and(collect($miembrosB->json('data'))->pluck('email')->all())->not->toContain($s['email_invitado'])
        ->and(collect($miembrosB->json('data'))->pluck('email')->all())->not->toContain($s['dueno_a']['email']);

    // Las invitaciones de A no aparecen en B.
    $invB = e2eApi($s['dueno_b']['access'], $b['slug'])->get('/tenant/invitations');
    expect($invB->status())->toBe(200)
        ->and(collect($invB->json('data'))->pluck('email')->all())->not->toContain($s['email_invitado']);

    // El mismo texto de Idempotency-Key en otra empresa es OTRA reserva (no repite la respuesta de A).
    $clave = 'inv-'.$s['run'];
    $enB = e2eApi($s['dueno_b']['access'], $b['slug'])->withHeaders(['Idempotency-Key' => $clave])
        ->post('/tenant/invitations', ['email' => 'solo-b-'.$s['run'].'@example.test']);
    expect($enB->status())->toBe(201)->and($enB->header('Idempotent-Replayed'))->toBe('');
})->depends('un miembro raso entra a la empresa y lee, pero no gestiona miembros ni invitaciones');

test('la exportación de miembros se genera por la cola y solo contiene a la propia empresa', function () {
    $s = e2e(E2E_EQ);

    $crea = e2eApi($s['dueno_a']['access'], $s['empresa_a']['slug'])->withHeaders(['Idempotency-Key' => 'exp-'.$s['run']])
        ->post('/tenant/exports/members', ['format' => 'csv']);
    expect($crea->status())->toBeIn([201, 202], $crea->body());
    $id = $crea->json('data.id');

    $listo = e2eEsperar(function () use ($s, $id) {
        $r = e2eApi($s['dueno_a']['access'], $s['empresa_a']['slug'])->get("/tenant/exports/{$id}");

        return in_array($r->json('data.status'), ['completed', 'failed'], true) ? $r : null;
    }, 60);
    expect($listo)->not->toBeNull('la exportación no terminó en 60 s: ¿Horizon consume la cola?')
        ->and($listo->json('data.status'))->toBe('completed');

    $bajada = e2eApi($s['dueno_a']['access'], $s['empresa_a']['slug'])->get("/tenant/exports/{$id}/download");
    expect($bajada->status())->toBe(200);
    $csv = $bajada->body();
    expect($csv)->toContain($s['dueno_a']['email'])
        ->and($csv)->toContain($s['email_invitado'])
        ->and($csv)->not->toContain($s['dueno_b']['email']);

    // Un reintento con la misma clave no genera otra exportación.
    $otra = e2eApi($s['dueno_a']['access'], $s['empresa_a']['slug'])->withHeaders(['Idempotency-Key' => 'exp-'.$s['run']])
        ->post('/tenant/exports/members', ['format' => 'csv']);
    expect($otra->header('Idempotent-Replayed'))->toBe('true')->and($otra->json('data.id'))->toBe($id);

    // La exportación de A no se ve ni se descarga desde B.
    expect(e2eApi($s['dueno_b']['access'], $s['empresa_b']['slug'])->get("/tenant/exports/{$id}")->status())->toBe(404);
    expect(e2eApi($s['dueno_b']['access'], $s['empresa_b']['slug'])->get("/tenant/exports/{$id}/download")->status())->toBe(404);
})->depends('aislamiento A/B: ninguna de las dos empresas ve ni toca lo de la otra');
