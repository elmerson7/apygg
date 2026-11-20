import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { elegirEmpresa, loginUI } from './helpers'

// Fase 2 del impago: `prepare.spec.ts` dejó una suscripción en prueba con tarjeta rechazada y el script adelantó el
// reloj y lanzó `billing:renew` (cobro fallido -> past_due) y 8 días más (pasada la gracia). Aquí se ve por la UI.
test('impago: aviso en la bandeja, escritura bloqueada con 402 y desbloqueo al pagar con otra tarjeta', async ({ page }, info) => {
  const estado = JSON.parse(readFileSync(`e2e/.state/dunning-${info.project.name}.json`, 'utf8')) as { email: string; slug: string }
  await loginUI(page, estado.email)
  await elegirEmpresa(page, estado.slug)

  // El aviso obligatorio de pago fallido llega a la bandeja (cola real) y la insignia cuenta lo no leído.
  await expect(page.getByTestId('unread-badge')).toBeVisible({ timeout: 30_000 })
  await page.getByRole('link', { name: /Notificaciones/ }).click()
  await expect(page.getByTestId('notifications')).toContainText('billing_payment_failed')

  // Suscripción vencida con una factura abierta.
  await page.getByRole('link', { name: 'Suscripción' }).click()
  await expect(page.getByTestId('sub-status')).toHaveText('past_due')
  const abierta = page.getByTestId('invoices').locator('tr[data-status="open"]')
  await expect(abierta).toHaveCount(1)

  // Pasada la gracia, escribir da 402 y la banda explica el bloqueo; leer sigue funcionando.
  await page.getByRole('link', { name: 'Miembros' }).click()
  await expect(page.getByTestId('members-table')).toContainText(estado.email)
  await page.getByTestId('invite-email').fill(`dun-${Date.now()}@example.test`)
  await page.getByTestId('invite-submit').click()
  await expect(page.getByTestId('error-code').first()).toHaveText('payment_required')
  await expect(page.getByTestId('billing-blocked')).toBeVisible()

  // Cambiar la tarjeta y pagar la factura reactiva la empresa.
  await page.getByRole('link', { name: 'Ir a suscripción y pagar' }).click()
  await page.getByTestId('card-select').selectOption('tok_visa')
  await page.getByTestId('card-save').click()
  await expect(page.getByTestId('card-saved')).toBeVisible()
  await abierta.getByRole('button', { name: 'Pagar' }).click()
  await expect(page.getByTestId('sub-status')).toHaveText('active')
  await expect(page.getByTestId('invoices').locator('tr[data-status="paid"]')).toHaveCount(1)

  await page.getByRole('link', { name: 'Miembros' }).click()
  await page.getByTestId('invite-email').fill(`desbloqueo-${Date.now()}@example.test`)
  await page.getByTestId('invite-submit').click()
  await expect(page.getByTestId('invite-ok')).toBeVisible()
  await expect(page.getByTestId('billing-blocked')).toHaveCount(0)

  // Ya sin bloqueo se puede marcar la notificación como leída y la insignia baja.
  await page.getByRole('link', { name: /Notificaciones/ }).click()
  // El aviso del pago llega por la cola (Horizon) después de pagar: se espera a que aparezca (la bandeja se refresca
  // sola cada 10 s) para que «marcar todo» no se cruce con una notificación nueva.
  await expect(page.getByTestId('notifications')).toContainText('Factura pagada', { timeout: 40_000 })
  await page.getByTestId('read-all').click()
  await expect(page.getByTestId('notifications').locator('li.unread')).toHaveCount(0)
  await expect(page.getByTestId('unread-badge')).toHaveCount(0, { timeout: 15_000 })
})
