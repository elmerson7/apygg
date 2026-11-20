// Errores de la API. Todo error trae `code` (estable: se ramifica por él, no por el texto) y `request_id`
// (lo que se le da a soporte). Formas: 422 con `errors` por campo, RFC 7807 en 5xx (`title`/`detail`), 402 con
// `errors.suggested_action`. Ver docs/api-response-structure.md.

export interface SuggestedAction {
  action: string
  method?: string
  url?: string
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly requestId: string | null
  readonly fields: Record<string, string[]>
  readonly suggestedAction: SuggestedAction | null

  constructor(init: {
    status: number
    code: string
    message: string
    requestId?: string | null
    fields?: Record<string, string[]>
    suggestedAction?: SuggestedAction | null
  }) {
    super(init.message)
    this.name = 'ApiError'
    this.status = init.status
    this.code = init.code
    this.requestId = init.requestId ?? null
    this.fields = init.fields ?? {}
    this.suggestedAction = init.suggestedAction ?? null
  }

  /** 402: la empresa tiene la escritura bloqueada por el estado de su suscripción. */
  get isPaymentRequired(): boolean {
    return this.status === 402
  }
}

type Json = Record<string, unknown>

const isObject = (v: unknown): v is Json => typeof v === 'object' && v !== null && !Array.isArray(v)

function fieldsFrom(errors: unknown): Record<string, string[]> {
  const out: Record<string, string[]> = {}
  if (!isObject(errors)) return out
  for (const [key, value] of Object.entries(errors)) {
    if (Array.isArray(value) && value.every((v) => typeof v === 'string')) out[key] = value as string[]
  }
  return out
}

function actionFrom(body: Json): SuggestedAction | null {
  const errors = isObject(body.errors) ? body.errors : null
  const raw = (errors && errors.suggested_action) ?? body.suggested_action
  return isObject(raw) && typeof raw.action === 'string' ? (raw as unknown as SuggestedAction) : null
}

/** Convierte el cuerpo de una respuesta de error (o su ausencia) en un ApiError. */
export function parseApiError(status: number, body: unknown, headers?: Headers): ApiError {
  const json: Json = isObject(body) ? body : {}
  const code = typeof json.code === 'string' ? json.code : status === 0 ? 'network_error' : 'http_error'
  const message =
    (typeof json.message === 'string' && json.message) ||
    (typeof json.detail === 'string' && json.detail) ||
    (typeof json.title === 'string' && json.title) ||
    `Error ${status}`
  const requestId =
    (typeof json.request_id === 'string' && json.request_id) || headers?.get('X-Request-ID') || null

  return new ApiError({ status, code, message, requestId, fields: fieldsFrom(json.errors), suggestedAction: actionFrom(json) })
}

/** Texto para mostrar a la persona: el mensaje del servidor y, si hay, el request_id para soporte. */
export function describeError(error: unknown): { message: string; code: string; requestId: string | null } {
  if (error instanceof ApiError) return { message: error.message, code: error.code, requestId: error.requestId }
  if (error instanceof Error) return { message: error.message, code: 'client_error', requestId: null }
  return { message: 'Error desconocido', code: 'client_error', requestId: null }
}
