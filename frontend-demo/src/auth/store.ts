// Estado de sesión del cliente: tokens, empresa activa y avisos de la API. Persistido en localStorage (es una demo:
// un front de producción preferiría memoria + cookie httpOnly). Los listeners notifican a React vía useSyncExternalStore.
import { useSyncExternalStore } from 'react'

export interface SessionUser {
  id: number
  name: string
  email: string
}

export interface SessionState {
  access: string | null
  refresh: string | null
  user: SessionUser | null
  tenant: string | null // slug de la empresa activa (cabecera X-Tenant)
  warning: string | null // X-Subscription-Warning de la última respuesta
  blocked: string | null // código del último 402 (payment_required, subscription_inactive...)
}

const KEY = 'apygg-demo-session'
const EMPTY: SessionState = { access: null, refresh: null, user: null, tenant: null, warning: null, blocked: null }

function safeRead(): SessionState {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return EMPTY
    return { ...EMPTY, ...(JSON.parse(raw) as Partial<SessionState>), warning: null, blocked: null }
  } catch {
    return EMPTY
  }
}

let state: SessionState = safeRead()
const listeners = new Set<() => void>()

function persist() {
  try {
    const { access, refresh, user, tenant } = state
    localStorage.setItem(KEY, JSON.stringify({ access, refresh, user, tenant }))
  } catch {
    /* sin almacenamiento: la sesión vive solo en memoria */
  }
}

export function getSession(): SessionState {
  return state
}

export function setSession(patch: Partial<SessionState>, options: { persist?: boolean } = {}) {
  state = { ...state, ...patch }
  if (options.persist !== false) persist()
  listeners.forEach((l) => l())
}

/** Relee de localStorage (otra pestaña pudo rotar el refresh token). */
export function reloadFromStorage() {
  const fresh = safeRead()
  state = { ...state, access: fresh.access, refresh: fresh.refresh }
  listeners.forEach((l) => l())
}

export function clearSession() {
  state = EMPTY
  try {
    localStorage.removeItem(KEY)
  } catch {
    /* nada que limpiar */
  }
  listeners.forEach((l) => l())
}

export function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function useSession(): SessionState {
  return useSyncExternalStore(subscribe, getSession, getSession)
}

if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => {
    if (event.key === KEY) reloadFromStorage()
  })
}
