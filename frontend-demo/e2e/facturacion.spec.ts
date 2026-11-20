import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { crearEmpresa, elegirEmpresa, loginUI, registrar, runId } from './helpers'

test('contratar el plan simulado (prueba), dar de baja, recontratar y ver la factura con su PDF', async ({ page, request }, info) => {
  const run = runId(info.project.name)
  const dueno = await registrar(request, 'fact', run)
  const slug = `fe-fact-${run}`
  await crearEmpresa(request, dueno.access, slug, `Facturación ${run}`)
  await loginUI(page, dueno.email)
  await elegirEmpresa(page, slug)
  await page.getByRole('link', { name: 'Suscripción' }).click()

  await expect(page.getByText('no tiene suscripción')).toBeVisible()
  await page.getByTestId('plan-select').selectOption('pro')
  await page.getByTestId('subscribe').click()
  await expect(page.getByTestId('sub-status')).toHaveText('trialing')
  await expect(page.getByTestId('subscription')).toContainText('29,00')
  await expect(page.getByText('la prueba no factura')).toBeVisible()

  // La baja inmediata bloquea la escritura (402 subscription_inactive) y la banda lo dice.
  await page.getByTestId('cancel-now').click()
  await expect(page.getByTestId('sub-status')).toHaveText('canceled')
  await page.getByRole('link', { name: 'Miembros' }).click()
  await page.getByTestId('invite-email').fill(`x-${run}@example.test`)
  await page.getByTestId('invite-submit').click()
  await expect(page.getByTestId('error-code').first()).toHaveText('subscription_inactive')
  await expect(page.getByTestId('billing-blocked')).toBeVisible()
  await expect(page.getByTestId('members-table')).toContainText(dueno.email) // la lectura sigue abierta

  // Recontratar (la prueba ya se usó): cobra al momento con la tarjeta simulada y emite la factura 1.
  await page.getByRole('link', { name: 'Ir a suscripción y pagar' }).click()
  await page.getByTestId('subscribe').click()
  await expect(page.getByTestId('sub-status')).toHaveText('active')
  const fila = page.getByTestId('invoices').locator('tbody tr').first()
  await expect(fila).toContainText(/F-\d{4}-\d{6}/)
  await expect(fila).toContainText('paid')

  const [pdf] = await Promise.all([page.waitForEvent('download'), fila.getByRole('button', { name: 'PDF' }).click()])
  expect(pdf.suggestedFilename()).toMatch(/^F-\d{4}-\d{6}\.pdf$/)
  expect(readFileSync((await pdf.path())!).subarray(0, 4).toString()).toBe('%PDF')

  // Con la suscripción activa la escritura vuelve y el aviso de bloqueo desaparece.
  await page.getByRole('link', { name: 'Miembros' }).click()
  await page.getByTestId('invite-email').fill(`ok-${run}@example.test`)
  await page.getByTestId('invite-submit').click()
  await expect(page.getByTestId('invite-ok')).toBeVisible()
  await expect(page.getByTestId('billing-blocked')).toHaveCount(0)
})
