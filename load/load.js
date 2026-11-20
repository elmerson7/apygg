// Carga: tráfico esperado en producción (mezcla lectura/escritura) sostenido unos minutos.
//   LOAD_VUS (20) · LOAD_RAMP (1m) · LOAD_HOLD (5m) · LOAD_WRITE_RATIO (0.2)
import { TREND_STATS, VUS, thresholds } from './lib/config.js';
import { summaryJson } from './lib/flows.js';
export { loginFlow, mixed } from './lib/flows.js';

export const options = {
  summaryTrendStats: TREND_STATS,
  scenarios: {
    mezcla: {
      executor: 'ramping-vus',
      exec: 'mixed',
      startVUs: 0,
      stages: [
        { duration: __ENV.LOAD_RAMP || '1m', target: VUS },
        { duration: __ENV.LOAD_HOLD || '5m', target: VUS },
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '15s',
    },
    login: { executor: 'constant-arrival-rate', exec: 'loginFlow', rate: 1, timeUnit: '5s', duration: __ENV.LOAD_HOLD || '5m', preAllocatedVUs: 2, maxVUs: 5 },
  },
  thresholds: thresholds(),
};

export const handleSummary = summaryJson('load');
