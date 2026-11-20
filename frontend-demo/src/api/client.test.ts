import { beforeEach, describe, expect, it, vi } from 'vitest'
import { clearSession, getSession, setSession } from '../auth/store'
import { call, createApiFetch } from './client'
import { ApiError } from './errors'

const json = (status: number, body: unknown, headers: Record<string, string> = {}) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } })

beforeEach(() => {
  clearSession()
  localStorage.clear()
})

describe('cliente de la API', () => {
  it('añade el token y la empresa (X-Tenant) a las rutas de empresa, no a las de auth', async () => {
    setSession({ access: 'tok', refresh: 'ref', tenant: 'acme' })
    const seen: Request[] = []
    const fetchMock = vi.fn(async (r: Request) => (seen.push(r), json(200, { success: true })))
    const f = createApiFetch(fetchMock as unknown as typeof fetch)

    await f(new Request('http://x/api/tenant/members'))
    await f(new Request('http://x/api/auth/me'))

    expect(seen[0].headers.get('Authorization')).toBe('Bearer tok')
    expect(seen[0].headers.get('X-Tenant')).toBe('acme')
    expect(seen[1].headers.get('X-Tenant')).toBeNull()
  })

  it('renueva el access caducado UNA vez y reintenta con el token nuevo', async () => {
    setSession({ access: 'viejo', refresh: 'r1' })
    const calls: string[] = []
    const fetchMock = vi.fn(async (input: Request | string, init?: RequestInit) => {
      if (typeof input === 'string') {
        calls.push('refresh')
        expect(JSON.parse(String(init?.body))).toEqual({ refresh_token: 'r1' })
        return json(200, { success: true, data: { access_token: 'nuevo', refresh_token: 'r2' } })
      }
      const auth = input.headers.get('Authorization')
      calls.push(`GET ${auth}`)
      return auth === 'Bearer nuevo' ? json(200, { success: true, data: [] }) : json(401, { code: 'token_expired' })
    })
    const f = createApiFetch(fetchMock as unknown as typeof fetch)

    const res = await f(new Request('http://x/api/tenant/members'))

    expect(res.status).toBe(200)
    expect(calls).toEqual(['GET Bearer viejo', 'refresh', 'GET Bearer nuevo'])
    expect(getSession().refresh).toBe('r2')
  })

  it('con refresh_superseded NO cierra la sesión (otra pestaña ya rotó el refresh)', async () => {
    setSession({ access: 'viejo', refresh: 'r1' })
    const fetchMock = vi.fn(async (input: Request | string) => {
      if (typeof input === 'string') {
        // Mientras tanto la otra pestaña guardó los tokens nuevos.
        localStorage.setItem('apygg-demo-session', JSON.stringify({ access: 'de-la-otra', refresh: 'r2', user: null, tenant: null }))
        return json(401, { code: 'refresh_superseded' })
      }
      return input.headers.get('Authorization') === 'Bearer de-la-otra' ? json(200, { success: true }) : json(401, { code: 'token_expired' })
    })
    const f = createApiFetch(fetchMock as unknown as typeof fetch)

    const res = await f(new Request('http://x/api/user/sessions'))

    expect(res.status).toBe(200)
    expect(getSession().access).toBe('de-la-otra')
    expect(getSession().refresh).toBe('r2')
  })

  it('si el refresh falla de verdad, cierra la sesión', async () => {
    setSession({ access: 'viejo', refresh: 'r1' })
    const fetchMock = vi.fn(async (input: Request | string) => (typeof input === 'string' ? json(401, { code: 'token_invalid' }) : json(401, { code: 'token_expired' })))
    const f = createApiFetch(fetchMock as unknown as typeof fetch)

    const res = await f(new Request('http://x/api/user/sessions'))

    expect(res.status).toBe(401)
    expect(getSession().access).toBeNull()
  })

  it('un 402 marca el bloqueo por suscripción y la cabecera de aviso queda visible', async () => {
    setSession({ access: 'tok' })
    const f = createApiFetch((async () => json(402, { code: 'payment_required' }, { 'X-Subscription-Warning': 'past_due; grace_ends_at=2026-01-01' })) as unknown as typeof fetch)

    await f(new Request('http://x/api/tenant/invitations', { method: 'POST' }))

    expect(getSession().blocked).toBe('payment_required')
    expect(getSession().warning).toContain('past_due')
  })

  it('call() convierte una respuesta de error en ApiError con code y request_id', async () => {
    const res = { response: json(422, {}), error: { code: 'validation_failed', message: 'mal', request_id: 'abc', errors: { name: ['x'] } }, data: undefined }
    // La Response de prueba lleva status 422: call() la trata como error.
    await expect(call(Promise.resolve(res))).rejects.toMatchObject({ code: 'validation_failed', requestId: 'abc', status: 422 })
    await expect(call(Promise.resolve(res))).rejects.toBeInstanceOf(ApiError)
  })
})
