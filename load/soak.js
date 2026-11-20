// Resistencia: carga moderada durante horas; busca fugas de memoria, conexiones y degradación lenta.
//   LOAD_VUS (20) · LOAD_SOAK_DURATION (2h). Las sesiones renuevan el token al caducar (401).
import { TREND_STATS, VUS, thresholds } from './lib/config.js';
import { summaryJson } from './lib/flows.js';
export { loginFlow, mixed } from './lib/flows.js';

export const options = {
  summaryTrendStats: TREND_STATS,
  scenarios: {
    mezcla: {
      executor: 'constant-vus',
      exec: 'mixed',
      vus: VUS,
      duration: __ENV.LOAD_SOAK_DURATION || '2h',
    },
  },
  thresholds: thresholds(),
};

export const handleSummary = summaryJson('soak');
