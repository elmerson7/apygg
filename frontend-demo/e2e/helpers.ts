import { createHmac } from 'node:crypto'
import { expect, type APIRequestContext, type Page } from '@playwright/test'

export const PASSWORD = 'Fe-Demo-Clave-' + 'x9!Zq7'

/** Identificador único por ejecución y proyecto: los recorridos son repetibles sin limpiar la BD. */
export function runId(projectName: string): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}-${projectName.slice(0, 3)}`
}

export interface Persona {
  id: number
  email: string
  name: string
  access: string
  refresh: string
}

/** La API se alcanza por el proxy del front (`/api`), igual que lo hace el navegador. */
const api = (path: string) => `/api${path}`

export async function registrar(request: APIRequestContext, alias: string, run: string): Promise<Persona> {
  const email = `fe-${alias}-${run}@example.test`
  const res = await request.post(api('/auth/register'), {
    data: { name: `Fe ${alias}`, email, password: PASSWORD, password_confirmation: PASSWORD },
  })
  expect(res.status(), await res.text()).toBe(201)
  const { data } = await res.json()
  return { id: data.user.id, email, name: `Fe ${alias}`, access: data.access_token, refresh: data.refresh_token }
}

export async function crearEmpresa(request: APIRequestContext, token: string, slug: string, name: string) {
  const res = await request.post(api('/tenants'), { headers: { Authorization: `Bearer ${token}` }, data: { name, slug } })
  expect(res.status(), await res.text()).toBe(201)
  return (await res.json()).data as { id: number; slug: string }
}

export async function loginUI(page: Page, email: string, password = PASSWORD) {
  await page.goto('/login')
  await page.getByTestId('login').fill(email)
  await page.getByTestId('password').fill(password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByTestId('whoami')).toHaveText(email)
}

/** Elige la empresa activa en el selector de la cabecera. */
export async function elegirEmpresa(page: Page, slug: string) {
  await page.getByTestId('tenant-select').selectOption(slug)
  await expect(page).toHaveURL(/\/miembros$/)
}

/** Busca un correo en Mailpit del stack (MAILPIT_URL) y devuelve su texto, esperando hasta `segundos`. */
export async function correoPara(request: APIRequestContext, email: string, segundos = 45): Promise<string> {
  const base = process.env.MAILPIT_URL ?? 'http://mailpit:8025'
  const limite = Date.now() + segundos * 1000
  while (Date.now() < limite) {
    const lista = await request.get(`${base}/api/v1/search`, { params: { query: `to:${email}` } })
    const id = (await lista.json()).messages?.[0]?.ID
    if (id) return (await (await request.get(`${base}/api/v1/message/${id}`)).json()).Text as string
    await new Promise((r) => setTimeout(r, 1000))
  }
  throw new Error(`No llegó ningún correo para ${email} en ${segundos} s (¿Horizon consume la cola?)`)
}

// ── TOTP (RFC 6238, HMAC-SHA1, 6 dígitos, 30 s) para el 2FA sin depender de librerías ──
function base32Decode(input: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const c of input.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0')
  const bytes: number[] = []
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2))
  return Buffer.from(bytes)
}

export function totp(secret: string, windowOffset = 0, now = Date.now()): string {
  const counter = Math.floor(now / 1000 / 30) + windowOffset
  const msg = Buffer.alloc(8)
  msg.writeBigUInt64BE(BigInt(counter))
  const hmac = createHmac('sha1', base32Decode(secret)).update(msg).digest()
  const offset = hmac[hmac.length - 1] & 0xf
  const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000
  return String(code).padStart(6, '0')
}

/** 1x1 PNG válido: pasa la comprobación de tipo real que hace la API al completar la subida. */
export const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
)
