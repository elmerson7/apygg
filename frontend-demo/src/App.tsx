import { useEffect, useState } from 'react'
import { Link, Navigate, NavLink, Outlet, Route, Routes, useNavigate } from 'react-router-dom'
import { api, call } from './api/client'
import { clearSession, setSession, useSession } from './auth/store'
import BillingPage from './pages/BillingPage'
import FilesPage from './pages/FilesPage'
import LoginPage from './pages/LoginPage'
import MembersPage from './pages/MembersPage'
import NotificationsPage from './pages/NotificationsPage'
import RolesPage from './pages/RolesPage'
import SecurityPage from './pages/SecurityPage'
import TenantsPage from './pages/TenantsPage'

function RequireAuth() {
  const { access } = useSession()
  return access ? <Outlet /> : <Navigate to="/login" replace />
}

function RequireTenant() {
  const { tenant } = useSession()
  return tenant ? <Outlet /> : <Navigate to="/empresas" replace />
}

/** Avisos globales de facturación: gracia por impago (X-Subscription-Warning) y bloqueo de escritura (402). */
function BillingBanner() {
  const { warning, blocked } = useSession()
  if (blocked) {
    return (
      <div className="banner banner-blocked" role="alert" data-testid="billing-blocked">
        <strong>Escritura bloqueada por la suscripción</strong> (<code>{blocked}</code>). Los datos siguen legibles.{' '}
        <Link to="/suscripcion">Ir a suscripción y pagar</Link>
      </div>
    )
  }
  if (warning) {
    return (
      <div className="banner banner-warning" role="status" data-testid="billing-warning">
        Pago pendiente: <code>{warning}</code>. <Link to="/suscripcion">Regularizar</Link>
      </div>
    )
  }
  return null
}

function Layout() {
  const session = useSession()
  const navigate = useNavigate()
  const [unread, setUnread] = useState(0)
  const [tenants, setTenants] = useState<{ slug: string; name: string }[]>([])

  useEffect(() => {
    let alive = true
    call(api.GET('/tenants/mine'))
      .then((r) => alive && setTenants((r as { data: { slug: string; name: string }[] }).data))
      .catch(() => undefined)
    return () => {
      alive = false
    }
  }, [session.tenant])

  useEffect(() => {
    if (!session.tenant) return
    let alive = true
    const tick = () =>
      call(api.GET('/user/notifications/unread-count'))
        .then((r) => alive && setUnread((r as { data: { unread: number } }).data.unread))
        .catch(() => undefined)
    void tick()
    const id = setInterval(tick, 10_000)
    return () => {
      alive = false
      clearInterval(id)
    }
  }, [session.tenant])

  const logout = async () => {
    try {
      await api.POST('/auth/logout')
    } finally {
      clearSession()
      navigate('/login')
    }
  }

  return (
    <div className="shell">
      <header className="topbar">
        <Link to="/" className="brand">APYGG demo</Link>
        <label className="tenant-switch">
          <span className="sr-only">Empresa activa</span>
          <select
            aria-label="Empresa activa"
            data-testid="tenant-select"
            value={session.tenant ?? ''}
            onChange={(e) => {
              setSession({ tenant: e.target.value || null })
              navigate('/miembros')
            }}
          >
            <option value="">Elegir empresa…</option>
            {tenants.map((t) => (
              <option key={t.slug} value={t.slug}>{t.name}</option>
            ))}
          </select>
        </label>
        <span className="who" data-testid="whoami">{session.user?.email}</span>
        <button type="button" className="ghost" onClick={logout}>Salir</button>
      </header>
      <nav className="nav" aria-label="Secciones">
        <NavLink to="/empresas">Empresas</NavLink>
        <NavLink to="/miembros">Miembros</NavLink>
        <NavLink to="/roles">Roles</NavLink>
        <NavLink to="/archivos">Archivos</NavLink>
        <NavLink to="/notificaciones">
          Notificaciones{unread > 0 && <span className="badge" data-testid="unread-badge">{unread}</span>}
        </NavLink>
        <NavLink to="/suscripcion">Suscripción</NavLink>
        <NavLink to="/seguridad">Seguridad</NavLink>
      </nav>
      <BillingBanner />
      <main className="content">
        {/* La clave es la empresa activa: al cambiarla la página se monta de nuevo y no queda ningún dato de la anterior. */}
        <Outlet key={session.tenant ?? ''} />
      </main>
    </div>
  )
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<RequireAuth />}>
        <Route element={<Layout />}>
          <Route path="/" element={<Navigate to="/empresas" replace />} />
          <Route path="/empresas" element={<TenantsPage />} />
          <Route path="/seguridad" element={<SecurityPage />} />
          <Route element={<RequireTenant />}>
            <Route path="/miembros" element={<MembersPage />} />
            <Route path="/roles" element={<RolesPage />} />
            <Route path="/archivos" element={<FilesPage />} />
            <Route path="/notificaciones" element={<NotificationsPage />} />
            <Route path="/suscripcion" element={<BillingPage />} />
          </Route>
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
