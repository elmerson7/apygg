import { useState, type FormEvent } from 'react'
import { api, call } from '../api/client'
import { formatDate } from '../lib/format'
import { Card, Empty, ErrorNotice, Field, Loading, useAction, useLoad } from '../ui'

export default function SecurityPage() {
  const sessions = useLoad(async () => (await call(api.GET('/user/sessions'))).data ?? [], [])
  const twofa = useLoad(async () => (await call(api.GET('/user/2fa'))).data, [])

  const [setup, setSetup] = useState<{ secret: string; otpauth_url: string } | null>(null)
  const [code, setCode] = useState('')
  const [recovery, setRecovery] = useState<string[]>([])
  const [password, setPassword] = useState('')
  const [offCode, setOffCode] = useState('')

  const revoke = useAction(async (id: string) => {
    await call(api.DELETE('/user/sessions/{id}', { params: { path: { id } } }))
    await sessions.reload(true)
  })
  const revokeOthers = useAction(async () => {
    await call(api.DELETE('/user/sessions'))
    await sessions.reload(true)
  })
  const start = useAction(async () => {
    const res = await call(api.POST('/user/2fa/setup'))
    setSetup(res.data as { secret: string; otpauth_url: string })
  })
  const confirm = useAction(async () => {
    const res = await call(api.POST('/user/2fa/confirm', { body: { code } }))
    setRecovery(((res.data as { recovery_codes?: string[] }).recovery_codes) ?? [])
    setSetup(null)
    setCode('')
    await twofa.reload(true)
  })
  const disable = useAction(async () => {
    // Scramble documenta password/code como parámetros de query, pero un secreto en la URL acaba en logs: se manda
    // en el cuerpo JSON (la API lee ambos). Por eso se salta el tipado de la operación.
    await call(api.request('delete', '/user/2fa', { body: { password, code: offCode } } as never))
    setPassword('')
    setOffCode('')
    setRecovery([])
    await twofa.reload(true)
  })

  const enabled = (twofa.data as { enabled?: boolean } | undefined)?.enabled === true
  const onSubmit = (fn: () => Promise<unknown>) => (e: FormEvent) => {
    e.preventDefault()
    void fn()
  }

  return (
    <>
      <h1>Seguridad</h1>
      <Card title="Sesiones activas" actions={<button type="button" className="ghost danger" onClick={() => void revokeOthers.run()}>Cerrar las demás</button>}>
        {sessions.loading && <Loading />}
        <ErrorNotice error={sessions.error ?? revoke.error ?? revokeOthers.error} />
        {sessions.data?.length === 0 && <Empty>Sin sesiones.</Empty>}
        <ul className="list" data-testid="sessions">
          {sessions.data?.map((s) => (
            <li key={s.id}>
              <div>
                <strong>{s.device ?? 'Dispositivo desconocido'}</strong> {s.current && <span className="tag">esta sesión</span>}
                <div className="meta">{s.ip ?? '—'} · último uso {formatDate(s.last_used_at)}</div>
              </div>
              {!s.current && <button type="button" className="ghost danger" onClick={() => void revoke.run(s.id)}>Cerrar</button>}
            </li>
          ))}
        </ul>
      </Card>

      <Card title="Verificación en dos pasos (2FA)">
        <ErrorNotice error={twofa.error} />
        <p data-testid="twofa-status">Estado: <strong>{enabled ? 'activada' : 'desactivada'}</strong></p>
        {!enabled && !setup && <button type="button" onClick={() => void start.run()} disabled={start.busy}>Activar 2FA</button>}
        <ErrorNotice error={start.error} />
        {setup && (
          <form onSubmit={onSubmit(confirm.run)}>
            <p>Añade este secreto a tu autenticador (TOTP) y escribe el código de 6 dígitos:</p>
            <p><code data-testid="twofa-secret">{setup.secret}</code></p>
            <Field label="Código"><input value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" required data-testid="twofa-code" /></Field>
            <ErrorNotice error={confirm.error} />
            <button type="submit" disabled={confirm.busy}>Confirmar</button>
          </form>
        )}
        {recovery.length > 0 && (
          <div className="notice notice-ok" data-testid="recovery-codes">
            <strong>Guarda estos códigos de recuperación (se muestran una vez):</strong>
            <p className="chips">{recovery.map((c) => <code key={c}>{c}</code>)}</p>
          </div>
        )}
        {enabled && (
          <form onSubmit={onSubmit(disable.run)}>
            <h3>Desactivar</h3>
            <Field label="Contraseña"><input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required /></Field>
            <Field label="Código TOTP o de recuperación"><input value={offCode} onChange={(e) => setOffCode(e.target.value)} required /></Field>
            <ErrorNotice error={disable.error} />
            <button type="submit" className="danger" disabled={disable.busy}>Desactivar 2FA</button>
          </form>
        )}
      </Card>
    </>
  )
}
