import { api, call } from '../api/client'
import { formatDate } from '../lib/format'
import { Card, Empty, ErrorNotice, Loading, useAction, useLoad } from '../ui'

/** Bandeja con SONDEO cada 10 s (el en vivo con Reverb queda fuera de la demo: ver docs/websockets.md). */
export default function NotificationsPage() {
  const inbox = useLoad(async () => (await call(api.GET('/user/notifications'))).data ?? [], [], 10_000)

  const read = useAction(async (id: number) => {
    await call(api.POST('/user/notifications/{id}/read', { params: { path: { id } } }))
    await inbox.reload(true)
  })
  const readAll = useAction(async () => {
    await call(api.POST('/user/notifications/read-all'))
    await inbox.reload(true)
  })
  const remove = useAction(async (id: number) => {
    await call(api.DELETE('/user/notifications/{id}', { params: { path: { id } } }))
    await inbox.reload(true)
  })

  return (
    <>
      <h1>Notificaciones</h1>
      <Card
        title="Bandeja"
        actions={<button type="button" className="ghost" onClick={() => void readAll.run()} data-testid="read-all">Marcar todo como leído</button>}
      >
        {inbox.loading && <Loading />}
        <ErrorNotice error={inbox.error ?? read.error ?? readAll.error ?? remove.error} />
        {inbox.data?.length === 0 && <Empty>La bandeja está vacía.</Empty>}
        <ul className="list" data-testid="notifications">
          {inbox.data?.map((n) => (
            <li key={n.id} className={n.read ? 'read' : 'unread'} data-read={n.read}>
              <div>
                <strong>{n.title}</strong> {!n.read && <span className="tag">nueva</span>}
                {n.body && <div>{n.body}</div>}
                <div className="meta">{n.type} · {formatDate(n.created_at)}</div>
              </div>
              <div className="row">
                {!n.read && <button type="button" className="ghost" onClick={() => void read.run(n.id)}>Marcar leída</button>}
                <button type="button" className="ghost danger" onClick={() => void remove.run(n.id)}>Borrar</button>
              </div>
            </li>
          ))}
        </ul>
      </Card>
    </>
  )
}
