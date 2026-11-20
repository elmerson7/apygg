// Configuración común de los escenarios k6 (todo se parametriza por variable de entorno; docs/load-testing.md).
import { SharedArray } from 'k6/data';

const num = (name, fallback) => (__ENV[name] !== undefined && __ENV[name] !== '' ? Number(__ENV[name]) : fallback);

export const BASE_URL = (__ENV.LOAD_BASE_URL || 'http://127.0.0.1:8010').replace(/\/$/, '');
// Dentro del contenedor k6 el CSV se monta en /data/credentials.csv (ver make/load.mk).
export const CREDENTIALS_FILE = __ENV.LOAD_CREDENTIALS_FILE || '/data/credentials.csv';
// Filas del CSV que se usan: `owner` (por defecto; pueden escribir), `member` o `all`.
export const ROLES = (__ENV.LOAD_ROLES || 'owner').split(',').map((r) => r.trim());

export const VUS = num('LOAD_VUS', 20);
export const WRITE_RATIO = num('LOAD_WRITE_RATIO', 0.2); // fracción de iteraciones que escriben
export const THINK_TIME = num('LOAD_THINK_TIME', 1); // segundos entre iteraciones
export const INVITE_PACING = num('LOAD_INVITE_PACING', 5); // segundos entre invitaciones de un mismo usuario (límite 30/min de la ruta)
export const TREND_STATS = ['avg', 'p(90)', 'p(95)', 'p(99)', 'max'];

// Umbrales (ms / tasa) ajustables sin tocar los scripts.
export const P95_READ_MS = num('LOAD_P95_READ_MS', 800);
export const P95_WRITE_MS = num('LOAD_P95_WRITE_MS', 1500);
export const P95_LOGIN_MS = num('LOAD_P95_LOGIN_MS', 2000);
export const MAX_ERROR_RATE = num('LOAD_MAX_ERROR_RATE', 0.01);

export const num_ = num;

export const credentials = new SharedArray('credentials', function () {
  const lines = open(CREDENTIALS_FILE).split('\n').map((l) => l.trim()).filter((l) => l.length > 0);
  const header = lines.shift().split(',');
  return lines
    .map((l) => {
      const cols = l.split(',');
      const row = {};
      header.forEach((h, i) => (row[h] = cols[i]));
      return row;
    })
    .filter((r) => ROLES.includes('all') || ROLES.includes(r.tenant_role));
});

// Umbrales base compartidos por todos los escenarios; cada script los ajusta.
export function thresholds(overrides = {}) {
  return Object.assign(
    {
      http_req_failed: [`rate<${MAX_ERROR_RATE}`],
      checks: ['rate>0.99'],
      'http_req_duration{endpoint:login}': [`p(95)<${P95_LOGIN_MS}`],
      'http_req_duration{kind:read}': [`p(95)<${P95_READ_MS}`],
      'http_req_duration{kind:write}': [`p(95)<${P95_WRITE_MS}`],
    },
    overrides,
  );
}
