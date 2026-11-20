import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { api, call } from '../api/client'
import { setSession, useSession } from '../auth/store'
import { Card, ErrorNotice, Field, FieldErrors, useAction } from '../ui'

export default function LoginPage() {
  const session = useSession()
  const navigate = useNavigate()
  const [mode, setMode] = useState<'login' | 'register'>('login')
  const [challenge, setChallenge] = useState<string | null>(null)

  const [login, setLogin] = useState('')
  const [password, setPassword] = useState('')
  const [name, setName] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [code, setCode] = useState('')

  const enter = (auth: { user: { id: number; name: string; email: string }; access_token: string; refresh_token: string }) => {
    setSession({ access: auth.access_token, refresh: auth.refresh_token, user: auth.user })
    navigate('/empresas')
  }

  const signIn = useAction(async () => {
    const res = await call(api.POST('/auth/login', { body: { login, password } }))
    const data = res.data as { two_factor_required?: boolean; challenge_token?: string; access_token?: string }
    if (data.two_factor_required && data.challenge_token) {
      setChallenge(data.challenge_token)
      return
    }
    enter(res.data as Parameters<typeof enter>[0])
  })

  const verify = useAction(async () => {
    const res = await call(api.POST('/auth/2fa/verify', { body: { challenge_token: challenge!, code } }))
    enter(res.data as Parameters<typeof enter>[0])
  })

  const register = useAction(async () => {
    const res = await call(
      api.POST('/auth/register', { body: { name, email: login, password, password_confirmation: confirmation } }),
    )
    enter(res.data as Parameters<typeof enter>[0])
  })

  if (session.access) return <Navigate to="/empresas" replace />

  const submit = (fn: () => Promise<unknown>) => (e: FormEvent) => {
    e.preventDefault()
    void fn()
  }

  if (challenge) {
    return (
      <div className="auth">
        <Card title="Verificación en dos pasos">
          <p className="muted">Introduce el código de tu autenticador o un código de recuperación.</p>
          <form onSubmit={submit(verify.run)}>
            <Field label="Código">
              <input value={code} onChange={(e) => setCode(e.target.value)} autoComplete="one-time-code" inputMode="numeric" required data-testid="code" />
            </Field>
            <ErrorNotice error={verify.error} />
            <button type="submit" disabled={verify.busy}>Verificar</button>
          </form>
        </Card>
      </div>
    )
  }

  return (
    <div className="auth">
      <Card title={mode === 'login' ? 'Iniciar sesión' : 'Crear cuenta'}>
        {mode === 'login' ? (
          <form onSubmit={submit(signIn.run)}>
            <Field label="Correo">
              <input type="email" value={login} onChange={(e) => setLogin(e.target.value)} autoComplete="username" required data-testid="login" />
            </Field>
            <Field label="Contraseña">
              <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required data-testid="password" />
            </Field>
            <ErrorNotice error={signIn.error} />
            <button type="submit" disabled={signIn.busy}>Entrar</button>
          </form>
        ) : (
          <form onSubmit={submit(register.run)}>
            <Field label="Nombre">
              <input value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" required data-testid="name" />
              <FieldErrors error={register.error} field="name" />
            </Field>
            <Field label="Correo">
              <input type="email" value={login} onChange={(e) => setLogin(e.target.value)} autoComplete="email" required data-testid="login" />
              <FieldErrors error={register.error} field="email" />
            </Field>
            <Field label="Contraseña" hint="Mínimo 12 caracteres con mayúscula, minúscula, número y símbolo.">
              <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" required data-testid="password" />
              <FieldErrors error={register.error} field="password" />
            </Field>
            <Field label="Repite la contraseña">
              <input type="password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} autoComplete="new-password" required data-testid="password-confirmation" />
            </Field>
            <ErrorNotice error={register.error} />
            <button type="submit" disabled={register.busy}>Crear cuenta</button>
          </form>
        )}
        <p className="muted">
          <button type="button" className="link" onClick={() => setMode(mode === 'login' ? 'register' : 'login')}>
            {mode === 'login' ? '¿Sin cuenta? Regístrate' : '¿Ya tienes cuenta? Entra'}
          </button>
        </p>
      </Card>
    </div>
  )
}
