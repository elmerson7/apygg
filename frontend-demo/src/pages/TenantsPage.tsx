import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, call } from '../api/client'
import { setSession, useSession } from '../auth/store'
import { formatDate } from '../lib/format'
import { Card, Empty, ErrorNotice, Field, FieldErrors, Loading, useAction, useLoad } from '../ui'

const slugify = (s: string) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')

export default function TenantsPage() {
  const { tenant } = useSession()
  const navigate = useNavigate()
  const list = useLoad(async () => (await call(api.GET('/tenants/mine'))).data ?? [], [])
  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')

  const create = useAction(async () => {
    const res = await call(api.POST('/tenants', { body: { name, slug: slug || slugify(name) } }))
    setName('')
    setSlug('')
    await list.reload()
    if (res.data?.slug) {
      setSession({ tenant: res.data.slug })
      navigate('/miembros')
    }
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    void create.run()
  }

  return (
    <>
      <h1>Empresas</h1>
      <Card title="Mis empresas">
        {list.loading && <Loading />}
        <ErrorNotice error={list.error} />
        {list.data && list.data.length === 0 && <Empty>Aún no perteneces a ninguna empresa. Crea la primera.</Empty>}
        <ul className="list" data-testid="tenant-list">
          {list.data?.map((t) => (
            <li key={t.slug} className={t.slug === tenant ? 'active' : ''}>
              <div>
                <strong>{t.name}</strong> <code>{t.slug}</code>
                <div className="meta">{t.role} · {t.status} · desde {formatDate(t.created_at)}</div>
              </div>
              {t.slug === tenant ? (
                <span className="tag">Activa</span>
              ) : (
                <button type="button" onClick={() => { setSession({ tenant: t.slug }); navigate('/miembros') }} data-testid={`enter-${t.slug}`}>
                  Entrar
                </button>
              )}
            </li>
          ))}
        </ul>
      </Card>
      <Card title="Crear empresa">
        <form onSubmit={submit}>
          <Field label="Nombre">
            <input value={name} onChange={(e) => { setName(e.target.value); setSlug(slugify(e.target.value)) }} required data-testid="tenant-name" />
            <FieldErrors error={create.error} field="name" />
          </Field>
          <Field label="Identificador (slug)">
            <input value={slug} onChange={(e) => setSlug(e.target.value)} data-testid="tenant-slug" />
            <FieldErrors error={create.error} field="slug" />
          </Field>
          <ErrorNotice error={create.error} />
          <button type="submit" disabled={create.busy} data-testid="tenant-create">Crear empresa</button>
        </form>
      </Card>
    </>
  )
}
