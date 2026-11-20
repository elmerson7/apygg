import { useState } from 'react'
import { api, call } from '../api/client'
import { Card, Empty, ErrorNotice, Loading, useAction, useLoad } from '../ui'

const TENANT_ROLES = [
  { code: 'owner', text: 'Dueño: todo, incluida la facturación y gestionar a otros dueños.' },
  { code: 'admin', text: 'Administrador: miembros, invitaciones y facturación.' },
  { code: 'member', text: 'Miembro: usa la empresa; no gestiona personas ni pagos.' },
]

export default function RolesPage() {
  const members = useLoad(async () => (await call(api.GET('/tenant/members'))).data ?? [], [])
  // Los roles GLOBALES (Spatie) exigen el permiso `roles.read`: una persona normal recibe 403 y se explica.
  const roles = useLoad(async () => (await call(api.GET('/roles'))).data ?? [], [])
  const [selected, setSelected] = useState<{ id: number; name: string } | null>(null)
  const perms = useAction(async (id: number, name: string) => {
    const res = await call(api.GET('/roles/{id}/permissions', { params: { path: { id: String(id) } } }))
    setSelected({ id, name })
    setPermissions((res.data ?? []).map((p) => p.name))
  })
  const [permissions, setPermissions] = useState<string[]>([])

  const count = (code: string) => members.data?.filter((m) => m.role === code).length ?? 0

  return (
    <>
      <h1>Roles</h1>
      <Card title="Roles de la empresa">
        {members.loading && <Loading />}
        <ul className="list" data-testid="tenant-roles">
          {TENANT_ROLES.map((r) => (
            <li key={r.code}>
              <div>
                <strong>{r.code}</strong>
                <div className="meta">{r.text}</div>
              </div>
              <span className="tag">{count(r.code)} persona(s)</span>
            </li>
          ))}
        </ul>
        <p className="muted">El rol de cada persona se cambia en «Miembros».</p>
      </Card>

      <Card title="Roles globales de la plataforma">
        {roles.loading && <Loading />}
        <ErrorNotice error={roles.error} />
        {roles.error ? <Empty>Necesitas el permiso <code>roles.read</code> para ver los roles globales.</Empty> : null}
        <ul className="list" data-testid="global-roles">
          {roles.data?.map((r) => {
            const role = r as unknown as { id: number; name: string }
            return (
              <li key={role.id}>
                <strong>{role.name}</strong>
                <button type="button" className="ghost" onClick={() => void perms.run(role.id, role.name)}>Ver permisos</button>
              </li>
            )
          })}
        </ul>
        <ErrorNotice error={perms.error} />
        {selected && (
          <div data-testid="role-permissions">
            <h3>Permisos de {selected.name}</h3>
            <p className="chips">{permissions.map((p) => <code key={p}>{p}</code>)}</p>
          </div>
        )}
      </Card>
    </>
  )
}
