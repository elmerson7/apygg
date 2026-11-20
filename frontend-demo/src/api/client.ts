// Cliente tipado de la API: openapi-fetch sobre los tipos generados de docs/openapi.json (`npm run api:types`).
// Un `fetch` propio añade el token y la empresa (X-Tenant), renueva el access caducado UNA vez por petición
// (con un solo refresh en vuelo) y convierte los errores en ApiError. `refresh_superseded` NO cierra la sesión:
// otra pestaña ya rotó el refresh token (docs/sessions-and-2fa.md).
import createClient from 'openapi-fetch'
import { clearSession, getSession, reloadFromStorage, setSession } from '../auth/store'
import { ApiError, parseApiError } from './errors'
import type { paths } from './schema'

export const API_BASE: string = import.meta.env.VITE_API_BASE ?? '/api'

/** Rutas que no llevan empresa: autenticación, alta de empresas y catálogo público. */
const NO_TENANT = [/^\/auth\//, /^\/tenants/, /^\/plans/, /^\/health/, /^\/user\/(?!notifications)/, /^\/tenant\/invitations\/accept/]

function pathOf(request: Request): string {
  const url = new URL(request.url, 'http://localhost')
  return url.pathname.replace(new RegExp(`^${API_BASE}`), '') || '/'
}

interface RefreshResult {
  ok: boolean
}

let inFlight: Promise<RefreshResult> | null = null

/** Renueva los tokens. Un solo refresh a la vez; el resto de peticiones esperan su resultado. */
export function refreshTokens(fetchImpl: typeof fetch = fetch): Promise<RefreshResult> {
  inFlight ??= doRefresh(fetchImpl).finally(() => {
    inFlight = null
  })
  return inFlight
}

async function doRefresh(fetchImpl: typeof fetch): Promise<RefreshResult> {
  const sent = getSession().refresh
  if (!sent) return { ok: false }

  const response = await fetchImpl(`${API_BASE}/auth/refresh`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ refresh_token: sent }),
  })
  const body = (await response.json().catch(() => null)) as { data?: { access_token: string; refresh_token: string } } & { code?: string } | null

  if (response.ok && body?.data) {
    setSession({ access: body.data.access_token, refresh: body.data.refresh_token })
    return { ok: true }
  }

  if (body?.code === 'refresh_superseded') {
    // Carrera legítima (dos pestañas, reintento): la sesión sigue viva. Se usa el refresh más reciente que
    // guardó la otra pestaña; si es el mismo que enviamos, no se pierde la sesión: se reintenta con el access actual.
    reloadFromStorage()
    return { ok: getSession().refresh !== sent || getSession().access !== null }
  }

  clearSession()
  return { ok: false }
}

export function createApiFetch(fetchImpl: typeof fetch = (...a) => fetch(...a)): typeof fetch {
  const prepare = (request: Request): Request => {
    const { access, tenant } = getSession()
    const headers = new Headers(request.headers)
    headers.set('Accept', 'application/json')
    if (access) headers.set('Authorization', `Bearer ${access}`)
    if (tenant && !NO_TENANT.some((re) => re.test(pathOf(request)))) headers.set('X-Tenant', tenant)
    return new Request(request, { headers })
  }

  return async (input, init) => {
    const original = input instanceof Request ? input : new Request(input, init)
    const retry = original.clone()
    let response = await fetchImpl(prepare(original))

    const isAuthCall = pathOf(original).startsWith('/auth/')
    if (response.status === 401 && !isAuthCall && getSession().refresh) {
      const result = await refreshTokens(fetchImpl)
      if (result.ok) response = await fetchImpl(prepare(retry))
    }

    const warning = response.headers.get('X-Subscription-Warning')
    if (warning !== getSession().warning) setSession({ warning }, { persist: false })
    if (response.status === 402) {
      const body = await response.clone().json().catch(() => null)
      setSession({ blocked: typeof body?.code === 'string' ? body.code : 'payment_required' }, { persist: false })
    } else if (response.ok && getSession().blocked && !isAuthCall && original.method !== 'GET') {
      setSession({ blocked: null }, { persist: false })
    }
    return response
  }
}

export const api = createClient<paths>({ baseUrl: API_BASE, fetch: createApiFetch() })

/** Lanza ApiError si la respuesta no fue exitosa; devuelve `data` ya sin el sobre `{ success, data }`. */
export async function call<T extends { data?: unknown; error?: unknown; response: Response }>(
  promise: Promise<T>,
): Promise<NonNullable<T['data']>> {
  let result: T
  try {
    result = await promise
  } catch (cause) {
    throw new ApiError({ status: 0, code: 'network_error', message: cause instanceof Error ? cause.message : 'Sin conexión con la API' })
  }
  if (result.response.ok) return (result.data ?? ({} as NonNullable<T['data']>)) as NonNullable<T['data']>
  throw parseApiError(result.response.status, result.error, result.response.headers)
}

/** El sobre de la API es `{ success, message, data }`: `unwrap` saca `data`. */
export function unwrap<D>(envelope: unknown): D {
  return (envelope as { data: D }).data
}
