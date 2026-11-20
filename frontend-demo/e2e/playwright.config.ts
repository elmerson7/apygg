import { defineConfig, devices } from '@playwright/test'

// Recorridos del cliente de pruebas contra el stack temporal de `make frontend-e2e` (scripts/frontend-e2e.sh).
// El front se sirve con `vite preview` (build estático) y su proxy `/api` apunta a la API del stack (VITE_API_URL).
// Dos proyectos de navegador: escritorio y móvil (viewport + touch). `prepare` solo deja listo, por la API, el
// escenario de impago; el script lo sigue con el reloj adelantado y el cron de facturación entre las dos fases.
const PORT = Number(process.env.FRONTEND_PORT ?? 4173)

export default defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.ts/,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  outputDir: 'test-results',
  use: {
    baseURL: process.env.FRONTEND_URL ?? `http://localhost:${PORT}`,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'off',
    locale: 'es-ES',
  },
  webServer: {
    command: `npm run preview -- --port ${PORT}`,
    url: `http://localhost:${PORT}`,
    reuseExistingServer: true,
    cwd: '..',
    timeout: 60_000,
  },
  projects: [
    { name: 'prepare', testMatch: /prepare\.spec\.ts/, use: { ...devices['Desktop Chrome'] } },
    { name: 'desktop', testIgnore: /prepare\.spec\.ts/, use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
    // Móvil: viewport estrecho con pantalla táctil, sobre Chromium (la imagen oficial lo trae).
    { name: 'mobile', testIgnore: /prepare\.spec\.ts/, use: { ...devices['Pixel 7'] } },
  ],
})
