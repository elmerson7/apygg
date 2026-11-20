import { useRef, useState, type FormEvent } from 'react'
import { api, call } from '../api/client'
import { ApiError } from '../api/errors'
import { saveBlob } from '../lib/download'
import { formatBytes, formatDate, newIdempotencyKey, sha256Hex } from '../lib/format'
import { Card, Empty, ErrorNotice, Field, Loading, useAction, useLoad } from '../ui'

export default function FilesPage() {
  const files = useLoad(async () => (await call(api.GET('/files'))).data ?? [], [])
  const usage = useLoad(async () => (await call(api.GET('/files/usage'))).data, [])
  const input = useRef<HTMLInputElement>(null)
  const [done, setDone] = useState<string | null>(null)
  const [step, setStep] = useState<string | null>(null)

  /** Subida DIRECTA al bucket: 1) intención (la API reserva cuota y firma la URL), 2) PUT al bucket, 3) completar. */
  const upload = useAction(async (file: File) => {
    setDone(null)
    setStep('Preparando…')
    const bytes = await file.arrayBuffer()
    const intent = await call(
      api.POST('/files/uploads', {
        body: {
          name: file.name,
          size: file.size,
          mime_type: file.type || 'application/octet-stream',
          checksum_sha256: await sha256Hex(bytes),
          is_public: false,
        },
        headers: { 'Idempotency-Key': newIdempotencyKey('up') },
      }),
    )
    const target = intent.data!.upload
    setStep('Subiendo al almacenamiento…')
    const put = await fetch(target.url, { method: 'PUT', headers: target.headers as Record<string, string>, body: file })
    if (!put.ok) {
      throw new ApiError({ status: put.status, code: 'upload_failed', message: `El almacenamiento rechazó la subida (HTTP ${put.status}).` })
    }
    setStep('Confirmando…')
    await call(api.POST('/files/uploads/{uuid}/complete', { params: { path: { uuid: intent.data!.id } } }))
    setStep(null)
    setDone(file.name)
    if (input.current) input.current.value = ''
    await Promise.all([files.reload(), usage.reload()])
  })

  const download = useAction(async (id: number, name: string) => {
    const blob = await call(api.GET('/files/{id}/download', { params: { path: { id: String(id) } }, parseAs: 'blob' }))
    saveBlob(blob as unknown as Blob, name)
  })

  const remove = useAction(async (id: number) => {
    await call(api.DELETE('/files/{id}', { params: { path: { id: String(id) } } }))
    await Promise.all([files.reload(), usage.reload()])
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const file = input.current?.files?.[0]
    if (file) void upload.run(file).finally(() => setStep(null))
  }

  const u = usage.data as { used_bytes?: number; limit_bytes?: number | null } | undefined

  return (
    <>
      <h1>Archivos</h1>
      <Card title="Subir archivo">
        <form onSubmit={submit}>
          <Field label="Archivo" hint="Sube directo al almacenamiento con una URL firmada: la API no ve los bytes.">
            <input ref={input} type="file" required data-testid="file-input" />
          </Field>
          <ErrorNotice error={upload.error} />
          {step && <p className="muted" role="status">{step}</p>}
          {done && <p className="notice notice-ok" role="status" data-testid="upload-ok">«{done}» subido.</p>}
          <button type="submit" disabled={upload.busy} data-testid="upload-submit">Subir</button>
        </form>
        {u?.used_bytes !== undefined && (
          <p className="muted" data-testid="usage">Usado: {formatBytes(u.used_bytes)}{u.limit_bytes ? ` de ${formatBytes(u.limit_bytes)}` : ''}</p>
        )}
      </Card>
      <Card title="Mis archivos">
        {files.loading && <Loading />}
        <ErrorNotice error={files.error ?? download.error ?? remove.error} />
        {files.data?.length === 0 && <Empty>Sin archivos todavía.</Empty>}
        <ul className="list" data-testid="files-list">
          {files.data?.map((f) => (
            <li key={f.id}>
              <div>
                <strong>{f.name}</strong>
                <div className="meta">{f.formatted_size} · {f.mime_type} · {formatDate(f.created_at)}</div>
              </div>
              <div className="row">
                <button type="button" className="ghost" onClick={() => void download.run(f.id, f.filename || f.name)}>Descargar</button>
                <button type="button" className="ghost danger" onClick={() => void remove.run(f.id)}>Borrar</button>
              </div>
            </li>
          ))}
        </ul>
      </Card>
    </>
  )
}
