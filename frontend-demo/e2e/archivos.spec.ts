import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { PNG_1X1, crearEmpresa, elegirEmpresa, loginUI, registrar, runId } from './helpers'

test('subir un archivo directo al almacenamiento, descargarlo y borrarlo', async ({ page, request }, info) => {
  const run = runId(info.project.name)
  const dueno = await registrar(request, 'arch', run)
  const slug = `fe-arch-${run}`
  await crearEmpresa(request, dueno.access, slug, `Archivos ${run}`)

  await loginUI(page, dueno.email)
  await elegirEmpresa(page, slug)
  await page.getByRole('link', { name: 'Archivos' }).click()

  const nombre = `captura-${run}.png`
  await page.getByTestId('file-input').setInputFiles({ name: nombre, mimeType: 'image/png', buffer: PNG_1X1 })
  await page.getByTestId('upload-submit').click()

  await expect(page.getByTestId('upload-ok')).toContainText(nombre)
  await expect(page.getByTestId('files-list')).toContainText(nombre)
  await expect(page.getByTestId('usage')).toContainText('Usado')

  const [descarga] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Descargar' }).click()])
  const bytes = readFileSync((await descarga.path())!)
  expect(bytes.equals(PNG_1X1)).toBe(true)

  await page.getByRole('button', { name: 'Borrar' }).click()
  await expect(page.getByTestId('files-list').locator('li')).toHaveCount(0)
})

test('un archivo con tipo declarado falso se rechaza y no queda en la lista', async ({ page, request }, info) => {
  const run = runId(info.project.name)
  const dueno = await registrar(request, 'falso', run)
  const slug = `fe-falso-${run}`
  await crearEmpresa(request, dueno.access, slug, `Falso ${run}`)
  await loginUI(page, dueno.email)
  await elegirEmpresa(page, slug)
  await page.getByRole('link', { name: 'Archivos' }).click()

  await page.getByTestId('file-input').setInputFiles({ name: `falso-${run}.png`, mimeType: 'image/png', buffer: Buffer.from('<html>no soy un png</html>') })
  await page.getByTestId('upload-submit').click()

  await expect(page.getByTestId('error-code')).toHaveText('upload_mime_mismatch')
  await expect(page.getByTestId('files-list').locator('li')).toHaveCount(0)
})
