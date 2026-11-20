import { expect, test } from '@playwright/test'
import { PASSWORD, correoPara, crearEmpresa, elegirEmpresa, loginUI, registrar, runId } from './helpers'

test.describe.configure({ mode: 'serial' })

test.describe('empresas, invitación y aislamiento', () => {
  let run = ''
  let email = ''
  let slugA = ''
  let slugB = ''
  test.beforeAll(({}, info) => {
    run = runId(info.project.name)
    email = `fe-dueno-${run}@example.test`
    slugA = `fe-a-${run}`
    slugB = `fe-b-${run}`
  })

  test('crear una empresa por la interfaz la deja activa con la persona como dueña', async ({ page, request }) => {
    const persona = await registrar(request, 'dueno', run)
    expect(persona.email).toBe(email)
    await crearEmpresa(request, persona.access, slugB, `Empresa B ${run}`)
    await loginUI(page, email)

    await page.getByTestId('tenant-name').fill(`Empresa A ${run}`)
    await page.getByTestId('tenant-slug').fill(slugA)
    await page.getByTestId('tenant-create').click()

    await expect(page).toHaveURL(/\/miembros$/)
    await expect(page.getByTestId('tenant-select')).toHaveValue(slugA)
    await expect(page.getByTestId('members-table')).toContainText(email)
    await expect(page.getByTestId('members-table').locator('select')).toHaveValue('owner')
  })

  test('invitar por correo, aceptar con el token y verla como miembro', async ({ page, request }) => {
    await loginUI(page, email)
    await elegirEmpresa(page, slugA)

    const invitado = `fe-inv-${run}@example.test`
    await page.getByTestId('invite-email').fill(invitado)
    await page.getByTestId('invite-submit').click()
    await expect(page.getByTestId('invite-ok')).toBeVisible()
    await expect(page.getByTestId('invitations-list')).toContainText(invitado)
    await expect(page.getByTestId('invitations-list')).toContainText('pending')

    // El correo sale por la cola real (Horizon) a Mailpit con un token de un solo uso.
    const texto = await correoPara(request, invitado)
    const token = /token=([A-Za-z0-9]{64})/.exec(texto)?.[1]
    expect(token, 'el correo debe traer el enlace con el token').toBeTruthy()

    const persona = await request.post('/api/auth/register', { data: { name: 'Invitada', email: invitado, password: PASSWORD, password_confirmation: PASSWORD } })
    expect(persona.status()).toBe(201)
    const access = (await persona.json()).data.access_token
    const acepta = await request.post('/api/tenant/invitations/accept', { headers: { Authorization: `Bearer ${access}` }, data: { token } })
    expect(acepta.status(), await acepta.text()).toBe(200)

    await page.reload()
    await expect(page.getByTestId('members-table')).toContainText(invitado)
    await expect(page.getByTestId('invitations-list')).toContainText('accepted')
  })

  test('cambiar de empresa cambia los datos y la otra no ve nada de la primera (aislamiento)', async ({ page }) => {
    await loginUI(page, email)
    await elegirEmpresa(page, slugA)
    await expect(page.getByTestId('members-table')).toContainText(`fe-inv-${run}@example.test`)

    await elegirEmpresa(page, slugB)
    await expect(page.getByTestId('members-table')).toContainText(email)
    await expect(page.getByTestId('members-table')).not.toContainText(`fe-inv-${run}@example.test`)
    await expect(page.getByTestId('invitations-list')).not.toContainText(`fe-inv-${run}@example.test`)

    // La barra de empresas lista las dos y la activa queda marcada.
    await page.getByRole('link', { name: 'Empresas' }).click()
    await expect(page.getByTestId('tenant-list').locator('li')).toHaveCount(2)
    await expect(page.getByTestId('tenant-list').locator('li.active')).toContainText(slugB)
  })

  test('un miembro raso no puede invitar y ve el 403 con su código', async ({ page }) => {
    await loginUI(page, `fe-inv-${run}@example.test`)
    await elegirEmpresa(page, slugA)
    await page.getByTestId('invite-email').fill(`otro-${run}@example.test`)
    await page.getByTestId('invite-submit').click()
    await expect(page.getByTestId('error-notice').first()).toBeVisible()
    await expect(page.getByTestId('error-code').first()).toHaveText(/forbidden|unauthorized/)
  })

  test('los roles de la empresa se muestran y los globales exigen permiso', async ({ page }) => {
    await loginUI(page, email)
    await elegirEmpresa(page, slugA)
    await page.getByRole('link', { name: 'Roles' }).click()
    await expect(page.getByTestId('tenant-roles')).toContainText('owner')
    // La empresa A tiene una dueña y la persona invitada como miembro (dos personas en total; ninguna admin).
    const roles = page.getByTestId('tenant-roles').locator('li')
    await expect(roles.filter({ hasText: 'owner' })).toContainText('1 persona')
    await expect(roles.filter({ hasText: 'admin' })).toContainText('0 persona')
    await expect(roles.filter({ hasText: 'member' })).toContainText('1 persona')
    await expect(page.getByTestId('error-code')).toHaveText(/forbidden|unauthorized/)
  })
})
