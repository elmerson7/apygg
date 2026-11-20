import { expect, test } from '@playwright/test'
import { PASSWORD, loginUI, registrar, runId, totp } from './helpers'

test.describe.configure({ mode: 'serial' })

test.describe('sesión, errores y 2FA', () => {
  let run = ''
  test.beforeAll(({}, info) => {
    run = runId(info.project.name)
  })

  test('un login incorrecto muestra el error RFC 7807 con su code y request_id', async ({ page }) => {
    await page.goto('/login')
    await page.getByTestId('login').fill(`nadie-${run}@example.test`)
    await page.getByTestId('password').fill('Clave-Incorrecta-1!')
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page.getByTestId('error-notice')).toBeVisible()
    await expect(page.getByTestId('error-code')).not.toHaveText('')
    await expect(page.getByTestId('error-request-id')).toHaveText(/\S{8,}/)
  })

  test('registrarse por la interfaz entra a la app y salir limpia la sesión', async ({ page }) => {
    await page.goto('/login')
    await page.getByRole('button', { name: /Regístrate/ }).click()
    const email = `fe-reg-${run}@example.test`
    await page.getByTestId('name').fill('Persona Registrada')
    await page.getByTestId('login').fill(email)
    await page.getByTestId('password').fill(PASSWORD)
    await page.getByTestId('password-confirmation').fill(PASSWORD)
    await page.getByRole('button', { name: 'Crear cuenta' }).click()

    await expect(page.getByTestId('whoami')).toHaveText(email)
    await expect(page).toHaveURL(/\/empresas$/)

    await page.getByRole('button', { name: 'Salir' }).click()
    await expect(page).toHaveURL(/\/login$/)
    // Sin sesión, una ruta privada redirige al login.
    await page.goto('/miembros')
    await expect(page).toHaveURL(/\/login$/)
  })

  test('una contraseña débil se rechaza con el error por campo', async ({ page }) => {
    await page.goto('/login')
    await page.getByRole('button', { name: /Regístrate/ }).click()
    await page.getByTestId('name').fill('Débil')
    await page.getByTestId('login').fill(`fe-debil-${run}@example.test`)
    await page.getByTestId('password').fill('corta')
    await page.getByTestId('password-confirmation').fill('corta')
    await page.getByRole('button', { name: 'Crear cuenta' }).click()
    await expect(page.getByRole('alert').filter({ hasText: /contraseña|password/i }).first()).toBeVisible()
    await expect(page.getByTestId('error-code')).toHaveText('validation_failed')
  })

  test('activar el 2FA y entrar con el desafío TOTP', async ({ page, request }) => {
    const persona = await registrar(request, 'dos', run)
    await loginUI(page, persona.email)

    await page.getByRole('link', { name: 'Seguridad' }).click()
    await expect(page.getByTestId('sessions')).toContainText('esta sesión')
    await page.getByRole('button', { name: 'Activar 2FA' }).click()
    const secret = (await page.getByTestId('twofa-secret').textContent())!.trim()
    await page.getByTestId('twofa-code').fill(totp(secret))
    await page.getByRole('button', { name: 'Confirmar' }).click()
    await expect(page.getByTestId('recovery-codes')).toBeVisible()
    await expect(page.getByTestId('twofa-status')).toContainText('activada')

    await page.getByRole('button', { name: 'Salir' }).click()
    await page.getByTestId('login').fill(persona.email)
    await page.getByTestId('password').fill(PASSWORD)
    await page.getByRole('button', { name: 'Entrar' }).click()

    // El login no da tokens: pide el desafío. Un código malo falla; el TOTP de la ventana siguiente entra
    // (el de la ventana actual ya se gastó al confirmar: cada código vale una vez).
    await expect(page.getByRole('heading', { name: 'Verificación en dos pasos' })).toBeVisible()
    await page.getByTestId('code').fill('000000')
    await page.getByRole('button', { name: 'Verificar' }).click()
    await expect(page.getByTestId('error-code')).toHaveText('two_factor_invalid')
    await page.getByTestId('code').fill(totp(secret, 1))
    await page.getByRole('button', { name: 'Verificar' }).click()
    await expect(page.getByTestId('whoami')).toHaveText(persona.email)
  })

  test('las sesiones se listan y se revoca la de otro dispositivo', async ({ page, request }) => {
    const persona = await registrar(request, 'ses', run)
    // Segundo dispositivo: otro login por la API.
    const otro = await request.post('/api/auth/login', { headers: { 'User-Agent': 'E2E-Movil/1.0' }, data: { login: persona.email, password: PASSWORD } })
    expect(otro.status()).toBe(200)

    await loginUI(page, persona.email)
    await page.getByRole('link', { name: 'Seguridad' }).click()
    const lista = page.getByTestId('sessions').locator('li')
    await expect(lista).toHaveCount(3) // registro + login API + esta sesión
    await page.getByTestId('sessions').getByRole('button', { name: 'Cerrar', exact: true }).first().click()
    await expect(lista).toHaveCount(2)
  })
})
