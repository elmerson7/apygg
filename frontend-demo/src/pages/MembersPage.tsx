import { useState, type FormEvent } from 'react'
import { api, call } from '../api/client'
import { formatDate, newIdempotencyKey } from '../lib/format'
import { Card, Empty, ErrorNotice, Field, FieldErrors, Loading, useAction, useLoad } from '../ui'

type Role = 'owner' | 'admin' | 'member'

export default function MembersPage() {
  const members = useLoad(async () => (await call(api.GET('/tenant/members'))).data ?? [], [])
  const invitations = useLoad(async () => (await call(api.GET('/tenant/invitations'))).data ?? [], [])
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<Role>('member')
  const [lastLink, setLastLink] = useState<string | null>(null)

  const invite = useAction(async () => {
    // Una clave por intención: si la red falla y el usuario reintenta con el mismo formulario, no se duplica.
    const res = await call(
      api.POST('/tenant/invitations', { body: { email, role }, headers: { 'Idempotency-Key': newIdempotencyKey('inv') } }),
    )
    setLastLink(res.data?.email ?? email)
    setEmail('')
    await invitations.reload()
  })

  const revoke = useAction(async (id: number) => {
    await call(api.DELETE('/tenant/invitations/{id}', { params: { path: { id } } }))
    await invitations.reload()
  })

  const resend = useAction(async (id: number) => {
    await call(api.POST('/tenant/invitations/{id}/resend', { params: { path: { id } } }))
    await invitations.reload()
  })

  const changeRole = useAction(async (userId: number, newRole: Role) => {
    await call(api.PATCH('/tenant/members/{userId}', { params: { path: { userId } }, body: { role: newRole } }))
    await members.reload()
  })

  const remove = useAction(async (userId: number) => {
    await call(api.DELETE('/tenant/members/{userId}', { params: { path: { userId } } }))
    await members.reload()
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    void invite.run()
  }

  return (
    <>
      <h1>Miembros e invitaciones</h1>
      <Card title="Miembros">
        {members.loading && <Loading />}
        <ErrorNotice error={members.error} />
        <ErrorNotice error={changeRole.error ?? remove.error} />
        <div className="table-wrap">
          <table data-testid="members-table">
            <thead>
              <tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Desde</th><th /></tr>
            </thead>
            <tbody>
              {members.data?.map((m) => (
                <tr key={m.user_id}>
                  <td>{m.name}</td>
                  <td>{m.email}</td>
                  <td>
                    <select aria-label={`Rol de ${m.email}`} value={String(m.role)} onChange={(e) => void changeRole.run(m.user_id, e.target.value as Role)}>
                      <option value="owner">owner</option>
                      <option value="admin">admin</option>
                      <option value="member">member</option>
                    </select>
                  </td>
                  <td>{formatDate(m.joined_at)}</td>
                  <td><button type="button" className="ghost danger" onClick={() => void remove.run(m.user_id)}>Quitar</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      <Card title="Invitar a alguien">
        <form onSubmit={submit} className="inline-form">
          <Field label="Correo">
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required data-testid="invite-email" />
            <FieldErrors error={invite.error} field="email" />
          </Field>
          <Field label="Rol">
            <select value={role} onChange={(e) => setRole(e.target.value as Role)} data-testid="invite-role">
              <option value="member">member</option>
              <option value="admin">admin</option>
            </select>
          </Field>
          <button type="submit" disabled={invite.busy} data-testid="invite-submit">Enviar invitación</button>
        </form>
        <ErrorNotice error={invite.error} />
        {lastLink && <p className="notice notice-ok" role="status" data-testid="invite-ok">Invitación enviada a {lastLink}.</p>}
      </Card>

      <Card title="Invitaciones">
        {invitations.loading && <Loading />}
        <ErrorNotice error={invitations.error ?? revoke.error ?? resend.error} />
        {invitations.data?.length === 0 && <Empty>No hay invitaciones.</Empty>}
        <ul className="list" data-testid="invitations-list">
          {invitations.data?.map((i) => (
            <li key={i.id}>
              <div>
                <strong>{i.email}</strong> <span className="tag">{i.status}</span>
                <div className="meta">{i.role} · caduca {formatDate(i.expires_at)}</div>
              </div>
              {i.status === 'pending' && (
                <div className="row">
                  <button type="button" className="ghost" onClick={() => void resend.run(i.id)}>Reenviar</button>
                  <button type="button" className="ghost danger" onClick={() => void revoke.run(i.id)}>Revocar</button>
                </div>
              )}
            </li>
          ))}
        </ul>
      </Card>
    </>
  )
}
