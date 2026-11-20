import { mkdirSync, writeFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { crearEmpresa, registrar, runId } from './helpers'

// Fase 1 del escenario de impago (solo API): una empresa con suscripción en prueba y la tarjeta que será
// rechazada. El script (scripts/frontend-e2e.sh) adelanta después el reloj y lanza `billing:renew`; la fase 2
// (dunning.spec.ts) entra por la UI y ve el estado real. Una empresa por viewport: cada uno paga la suya.
for (const viewport of ['desktop', 'mobile']) {
  test(`prepara la empresa con suscripción para el impago (${viewport})`, async ({ request }) => {
    const run = runId(viewport)
    const dueno = await registrar(request, 'dun', run)
    const slug = `fe-dun-${run}`
    await crearEmpresa(request, dueno.access, slug, `Impago ${run}`)
    const headers = { Authorization: `Bearer ${dueno.access}`, 'X-Tenant': slug }

    const alta = await request.post('/api/tenant/billing/subscription', {
      headers: { ...headers, 'Idempotency-Key': `fe-dun-${run}` },
      data: { plan: 'pro', interval: 'month', currency: 'USD' },
    })
    expect(alta.status(), await alta.text()).toBe(201)
    expect((await alta.json()).data.status).toBe('trialing')

    const tarjeta = await request.put('/api/tenant/billing/payment-method', { headers, data: { token: 'tok_declined' } })
    expect(tarjeta.status(), await tarjeta.text()).toBe(200)

    mkdirSync('e2e/.state', { recursive: true })
    writeFileSync(`e2e/.state/dunning-${viewport}.json`, JSON.stringify({ email: dueno.email, slug }))
  })
}
