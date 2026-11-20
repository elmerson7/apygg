// Pico: salto brusco a un múltiplo de la carga normal, se mantiene un momento y vuelve; mide recuperación.
//   LOAD_VUS (20) · LOAD_SPIKE_FACTOR (10) · LOAD_SPIKE_HOLD (1m)
import { TREND_STATS, VUS, num_, thresholds } from './lib/config.js';
import { summaryJson } from './lib/flows.js';
export { loginFlow, mixed } from './lib/flows.js';

const peak = Math.round(VUS * num_('LOAD_SPIKE_FACTOR', 10));

export const options = {
  summaryTrendStats: TREND_STATS,
  scenarios: {
    mezcla: {
      executor: 'ramping-vus',
      exec: 'mixed',
      startVUs: VUS,
      stages: [
        { duration: '1m', target: VUS },
        { duration: '10s', target: peak },
        { duration: __ENV.LOAD_SPIKE_HOLD || '1m', target: peak },
        { duration: '10s', target: VUS },
        { duration: '2m', target: VUS },
        { duration: '20s', target: 0 },
      ],
      gracefulRampDown: '20s',
    },
  },
  thresholds: thresholds({
    http_req_failed: ['rate<0.10'],
    'http_req_duration{kind:read}': ['p(95)<3000'],
    'http_req_duration{kind:write}': ['p(95)<5000'],
    'http_req_duration{endpoint:login}': ['p(95)<5000'],
    checks: ['rate>0.90'],
  }),
};

export const handleSummary = summaryJson('spike');
