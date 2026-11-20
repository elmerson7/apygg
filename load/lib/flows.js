// Flujos de usuario reutilizados por todos los escenarios (humo, carga, estrés, pico, resistencia).
import http from 'k6/http';
import { check, sleep, fail } from 'k6';
import { BASE_URL, credentials, THINK_TIME, WRITE_RATIO, INVITE_PACING } from './config.js';

// UUID v4 sin dependencias remotas (k6 no debe necesitar red en la prueba).
function uuidv4() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

// Estado por VU: cada VU es un usuario sembrado y conserva su token entre iteraciones.
let session = null;

// Desplazamiento por VU: si su empresa está suspendida (seed:load suspende ~3 %), salta a la siguiente fila.
let offset = 0;

function user() {
  if (credentials.length === 0) {
    fail('credentials.csv sin filas para LOAD_ROLES: ejecuta `make seed-load` (docs/seed-load.md)');
  }
  return credentials[(__VU - 1 + offset) % credentials.length];
}

function login() {
  const u = user();
  const res = http.post(
    `${BASE_URL}/auth/login`,
    JSON.stringify({ login: u.email, password: u.password }),
    { headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, tags: { endpoint: 'login', kind: 'auth' } },
  );
  const ok = check(res, {
    'login 200': (r) => r.status === 200,
    'login con access_token': (r) => r.status === 200 && !!r.json('data.access_token'),
  });
  if (!ok) {
    logUnexpected('login', res);
    session = null;
    return null;
  }
  session = { token: res.json('data.access_token'), tenant: u.tenant_id };
  return session;
}

function ensureSession() {
  return session || login();
}

function headers(extra = {}) {
  return Object.assign(
    {
      Authorization: `Bearer ${session.token}`,
      'X-Tenant': String(session.tenant),
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    extra,
  );
}

// GET/POST autenticado; si el token caducó (401) vuelve a entrar una vez.
function call(method, path, endpoint, kind, body, extraHeaders) {
  if (!ensureSession()) return null;
  const send = () =>
    http.request(method, `${BASE_URL}${path}`, body === undefined ? null : JSON.stringify(body), {
      headers: headers(extraHeaders),
      tags: { endpoint, kind },
    });
  let res = send();
  if (res.status === 401) {
    if (!login()) return res;
    res = send();
  }
  if (res.status === 403 && String(res.body).indexOf('tenant_suspended') !== -1) {
    offset += 1; // empresa suspendida: cambia de usuario y repite una vez
    session = null;
    if (!login()) return res;
    res = send();
  }
  logUnexpected(endpoint, res);
  return res;
}

// Diagnóstico: deja en stderr una muestra (máx. 1 de cada 25 por VU) de las respuestas de error, con el motivo.
function logUnexpected(endpoint, res) {
  if (res.status >= 400 && (unexpected++ % 25 === 0)) {
    console.warn(`${endpoint} -> ${res.status} ${String(res.body).slice(0, 160)}`);
  }
}
let unexpected = 0;

const okRead = (name) => ({ [`${name} 200`]: (r) => r !== null && r.status === 200 });

export function loginFlow() {
  session = null;
  login();
  sleep(THINK_TIME);
}

export function browse() {
  const page = 1 + Math.floor(Math.random() * 3);
  let r = call('GET', `/tenant/members?per_page=20&page=${page}`, 'members', 'read');
  check(r, okRead('members'));
  r = call('GET', '/tenant/billing/invoices?per_page=20', 'invoices', 'read');
  check(r, okRead('invoices'));
  r = call('GET', '/user/notifications?per_page=20', 'inbox', 'read');
  check(r, okRead('inbox'));
  r = call('GET', '/user/notifications/unread-count', 'inbox-count', 'read');
  check(r, okRead('inbox-count'));
  r = call('GET', '/files?per_page=20', 'files', 'read');
  check(r, okRead('files'));
  sleep(THINK_TIME);
}

// Crea un recurso con Idempotency-Key y repite la MISMA petición: debe devolver la respuesta guardada.
export function writeIdempotent() {
  const key = uuidv4();
  const body = { email: `k6-${__VU}-${__ITER}-${Date.now()}@load.test`, role: 'member' };
  const first = call('POST', '/tenant/invitations', 'invitation-create', 'write', body, { 'Idempotency-Key': key });
  check(first, { 'invitación 201': (r) => r !== null && r.status === 201 });
  if (first && first.status === 201) {
    const replay = call('POST', '/tenant/invitations', 'invitation-replay', 'write', body, { 'Idempotency-Key': key });
    check(replay, {
      'repetición 201 idéntica': (r) => r !== null && r.status === 201,
      'repetición marcada Idempotent-Replayed': (r) => r !== null && r.headers['Idempotent-Replayed'] === 'true',
    });
  }
  // Cada intento hace 2 peticiones y `throttle:30,1` de la ruta cuenta ambas: pausa para quedarse bajo el límite real.
  sleep(Math.max(THINK_TIME, INVITE_PACING));
}

// Subida directa presignada, paso 1 (intención + URL firmada). No toca el bucket.
export function presign() {
  const body = { name: `k6-${__VU}-${__ITER}.txt`, size: 128, mime_type: 'text/plain' };
  const r = call('POST', '/files/uploads', 'presign', 'write', body, { 'Idempotency-Key': uuidv4() });
  check(r, {
    'presign 201': (x) => x !== null && x.status === 201,
    'presign con URL': (x) => x !== null && x.status === 201 && !!x.json('data.upload.url'),
  });
  sleep(THINK_TIME);
}

// Mezcla realista: mayoría lecturas; WRITE_RATIO de las iteraciones escriben (mitad invitación, mitad presign).
export function mixed() {
  if (Math.random() < WRITE_RATIO) {
    if (Math.random() < 0.5) writeIdempotent();
    else presign();
  } else {
    browse();
  }
}

export function summaryJson(name) {
  return function (data) {
    const get = (m, stat) => (data.metrics[m] && data.metrics[m].values[stat] !== undefined ? data.metrics[m].values[stat] : null);
    const brief = {
      script: name,
      http_reqs: get('http_reqs', 'count'),
      rps: get('http_reqs', 'rate'),
      error_rate: get('http_req_failed', 'rate'),
      checks_rate: get('checks', 'rate'),
      p95_ms: get('http_req_duration', 'p(95)'),
      p99_ms: get('http_req_duration', 'p(99)'),
      avg_ms: get('http_req_duration', 'avg'),
      thresholds_ok: Object.values(data.metrics).every((m) => !m.thresholds || Object.values(m.thresholds).every((t) => t.ok)),
    };
    const lines = [
      `== ${name} ==`,
      `peticiones: ${brief.http_reqs}  (${brief.rps && brief.rps.toFixed(1)}/s)`,
      `errores http: ${brief.error_rate !== null ? (brief.error_rate * 100).toFixed(2) : '?'} %   checks ok: ${brief.checks_rate !== null ? (brief.checks_rate * 100).toFixed(2) : '?'} %`,
      `latencia ms  avg ${brief.avg_ms && brief.avg_ms.toFixed(0)}  p95 ${brief.p95_ms && brief.p95_ms.toFixed(0)}  p99 ${brief.p99_ms && brief.p99_ms.toFixed(0)}`,
      `umbrales: ${brief.thresholds_ok ? 'CUMPLIDOS' : 'FALLIDOS'}`,
    ];
    for (const m of Object.keys(data.metrics)) {
      if (m.indexOf('http_req_duration{') === 0 && data.metrics[m].values['p(95)'] !== undefined) {
        lines.push(`  ${m}: p95 ${data.metrics[m].values['p(95)'].toFixed(0)} ms`);
      }
    }
    const out = {};
    out['stdout'] = lines.join('\n') + '\n';
    out[`/results/${name}.json`] = JSON.stringify({ brief, metrics: data.metrics }, null, 2);
    return out;
  };
}
