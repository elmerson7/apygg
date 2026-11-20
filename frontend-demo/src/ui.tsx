import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { describeError } from './api/errors'

/** Carga de datos con estado de carga/error y recarga manual. `refreshMs` activa el sondeo. */
export function useLoad<T>(loader: () => Promise<T>, deps: unknown[], refreshMs?: number) {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [loading, setLoading] = useState(true)
  const loaderRef = useRef(loader)
  loaderRef.current = loader

  const reload = useCallback(async (silent = false) => {
    if (!silent) setLoading(true)
    try {
      setData(await loaderRef.current())
      setError(null)
    } catch (e) {
      setError(e)
    } finally {
      setLoading(false)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    void reload()
    if (!refreshMs) return
    const id = setInterval(() => void reload(true), refreshMs)
    return () => clearInterval(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reload, refreshMs, ...deps])

  return { data, error, loading, reload }
}

export function ErrorNotice({ error }: { error: unknown }) {
  if (!error) return null
  const { message, code, requestId } = describeError(error)
  return (
    <div className="notice notice-error" role="alert" data-testid="error-notice">
      <strong>{message}</strong>
      <div className="meta">
        código <code data-testid="error-code">{code}</code>
        {requestId && (
          <>
            {' · '}solicitud <code data-testid="error-request-id">{requestId}</code>
          </>
        )}
      </div>
    </div>
  )
}

export function FieldErrors({ error, field }: { error: unknown; field: string }) {
  const fields = (error as { fields?: Record<string, string[]> } | null)?.fields
  const messages = fields?.[field]
  if (!messages?.length) return null
  return <p className="field-error" role="alert">{messages.join(' ')}</p>
}

export function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
  return (
    <label className="field">
      <span>{label}</span>
      {children}
      {hint && <small>{hint}</small>}
    </label>
  )
}

export function Card({ title, children, actions }: { title?: string; children: ReactNode; actions?: ReactNode }) {
  return (
    <section className="card">
      {(title || actions) && (
        <header className="card-head">
          {title && <h2>{title}</h2>}
          {actions}
        </header>
      )}
      {children}
    </section>
  )
}

export function Loading({ label = 'Cargando…' }: { label?: string }) {
  return <p className="muted" role="status">{label}</p>
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="muted">{children}</p>
}

/** Ejecuta una acción de usuario con estado ocupado/error (evita dobles envíos). */
export function useAction<A extends unknown[], R>(fn: (...args: A) => Promise<R>) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const run = async (...args: A): Promise<R | undefined> => {
    setBusy(true)
    setError(null)
    try {
      return await fn(...args)
    } catch (e) {
      setError(e)
      return undefined
    } finally {
      setBusy(false)
    }
  }
  return { run, busy, error, setError }
}
