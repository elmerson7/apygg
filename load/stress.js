// Estrés: sube por escalones por encima de la carga normal hasta encontrar el punto de degradación.
//   LOAD_VUS (20) = escalón base; escalones x1, x2, x4, x6; LOAD_STEP (2m). Aborta si los errores superan 15 %.
import { TREND_STATS, VUS, thresholds } from './lib/config.js';
import { summaryJson } from './lib/flows.js';
export { loginFlow, mixed } from './lib/flows.js';

const step = __ENV.LOAD_STEP || '2m';

export const options = {
  summaryTrendStats: TREND_STATS,
  scenarios: {
    mezcla: {
      executor: 'ramping-vus',
      exec: 'mixed',
      startVUs: 0,
      stages: [
        { duration: step, target: VUS },
        { duration: step, target: VUS * 2 },
        { duration: step, target: VUS * 4 },
        { duration: step, target: VUS * 6 },
        { duration: '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: thresholds({
    http_req_failed: [{ threshold: 'rate<0.15', abortOnFail: true, delayAbortEval: '30s' }],
    'http_req_duration{kind:read}': ['p(95)<3000'],
    'http_req_duration{kind:write}': ['p(95)<5000'],
    'http_req_duration{endpoint:login}': ['p(95)<5000'],
    checks: ['rate>0.90'],
  }),
};

export const handleSummary = summaryJson('stress');
