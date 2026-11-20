// Humo: pocos VUs y menos de 1 minuto; comprueba que los flujos funcionan y que nada se degrada de entrada.
//   LOAD_VUS (3) · LOAD_SMOKE_DURATION (30s) · umbrales LOAD_P95_*_MS, LOAD_MAX_ERROR_RATE (config.js)
import { TREND_STATS, num_, thresholds } from './lib/config.js';
import { summaryJson } from './lib/flows.js';
export { loginFlow, browse, writeIdempotent, presign } from './lib/flows.js';

const vus = num_('LOAD_VUS', 3);
const duration = __ENV.LOAD_SMOKE_DURATION || '30s';

export const options = {
  summaryTrendStats: TREND_STATS,
  scenarios: {
    login: { executor: 'per-vu-iterations', exec: 'loginFlow', vus: 1, iterations: 3, maxDuration: '30s', startTime: '15s' },
    lectura: { executor: 'ramping-vus', exec: 'browse', startVUs: 1, stages: [{ duration: '8s', target: vus }, { duration, target: vus }], gracefulRampDown: '5s' },
    escritura: { executor: 'constant-vus', exec: 'writeIdempotent', vus: 1, duration, startTime: '4s' },
    presign: { executor: 'constant-vus', exec: 'presign', vus: 1, duration, startTime: '4s' },
  },
  thresholds: thresholds(),
};

export const handleSummary = summaryJson('smoke');
