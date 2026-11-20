import { useState, type FormEvent } from 'react'
import { api, call } from '../api/client'
import { saveBlob } from '../lib/download'
import { formatDate, formatMoney, newIdempotencyKey } from '../lib/format'
import { Card, Empty, ErrorNotice, Field, Loading, useAction, useLoad } from '../ui'

// Tokens de la pasarela SIMULADA (PAYMENTS_MODE=fake): tok_visa cobra, tok_declined rechaza. docs/billing.md
const TEST_CARDS = [
  { token: 'tok_visa', label: 'Visa de prueba (cobra)' },
  { token: 'tok_declined', label: 'Tarjeta rechazada' },
]

export default function BillingPage() {
  const plans = useLoad(async () => (await call(api.GET('/plans'))).data ?? [], [])
  const sub = useLoad(async () => (await call(api.GET('/tenant/billing/subscription'))).data ?? null, [])
  const invoices = useLoad(async () => (await call(api.GET('/tenant/billing/invoices', { params: { query: { per_page: 50 } } }))).data ?? [], [])

  const [plan, setPlan] = useState('pro')
  const [interval, setInterval] = useState<'month' | 'year'>('month')
  const [card, setCard] = useState('tok_visa')
  const [newCard, setNewCard] = useState('tok_visa')

  const refresh = () => Promise.all([sub.reload(true), invoices.reload(true)])

  const subscribe = useAction(async () => {
    // El plan trae un precio por moneda e intervalo: la demo contrata en USD y, si el plan no la tiene, en la primera disponible.
    const prices = plans.data?.find((p) => p.code === plan)?.prices.filter((p) => p.interval === interval) ?? []
    const price = prices.find((p) => p.currency === 'USD') ?? prices[0]
    await call(
      api.POST('/tenant/billing/subscription', {
        body: { plan, interval, currency: price?.currency ?? 'USD', payment_token: card },
        headers: { 'Idempotency-Key': newIdempotencyKey('sub') },
      }),
    )
    await refresh()
  })

  const [cardSaved, setCardSaved] = useState(false)
  const saveCard = useAction(async () => {
    setCardSaved(false)
    await call(api.PUT('/tenant/billing/payment-method', { body: { token: newCard } }))
    setCardSaved(true)
  })

  const pay = useAction(async (id: number) => {
    await call(api.POST('/tenant/billing/invoices/{id}/pay', { params: { path: { id } }, headers: { 'Idempotency-Key': newIdempotencyKey('pay') } }))
    await refresh()
  })

  const cancel = useAction(async (immediately: boolean) => {
    await call(api.POST('/tenant/billing/subscription/cancel', { body: { immediately } }))
    await refresh()
  })

  const pdf = useAction(async (id: number, number: string | null) => {
    const blob = await call(api.GET('/tenant/billing/invoices/{id}/pdf', { params: { path: { id } }, parseAs: 'blob' }))
    saveBlob(blob as unknown as Blob, `${number ?? `factura-${id}`}.pdf`)
  })

  const current = sub.data as { status: string; plan_code: string; plan_name?: string | null; interval: string; currency: string; unit_amount_minor: number; trial_ends_at: string | null; current_period_end: string; cancel_at_period_end: boolean } | null
  const live = current && !['canceled', 'expired'].includes(current.status)

  const submitSub = (e: FormEvent) => {
    e.preventDefault()
    void subscribe.run()
  }

  return (
    <>
      <h1>Suscripción</h1>
      <Card title="Estado">
        {sub.loading && <Loading />}
        <ErrorNotice error={sub.error} />
        {!sub.loading && !current && <Empty>Esta empresa no tiene suscripción (plan gratuito).</Empty>}
        {current && (
          <dl className="facts" data-testid="subscription">
            <div><dt>Estado</dt><dd><span className={`tag status-${current.status}`} data-testid="sub-status">{current.status}</span></dd></div>
            <div><dt>Plan</dt><dd>{current.plan_name ?? current.plan_code} · {formatMoney(current.unit_amount_minor, current.currency)}/{current.interval === 'year' ? 'año' : 'mes'}</dd></div>
            {current.trial_ends_at && <div><dt>Prueba hasta</dt><dd>{formatDate(current.trial_ends_at)}</dd></div>}
            <div><dt>Periodo hasta</dt><dd>{formatDate(current.current_period_end)}</dd></div>
          </dl>
        )}
        {live && (
          <div className="row">
            <button type="button" className="ghost danger" onClick={() => void cancel.run(true)} data-testid="cancel-now">Dar de baja ahora</button>
          </div>
        )}
        <ErrorNotice error={cancel.error} />
      </Card>

      {!live && (
        <Card title="Contratar un plan">
          {plans.loading && <Loading />}
          <ErrorNotice error={plans.error} />
          <form onSubmit={submitSub}>
            <Field label="Plan">
              <select value={plan} onChange={(e) => setPlan(e.target.value)} data-testid="plan-select">
                {plans.data?.map((p) => (
                  <option key={p.code} value={p.code}>
                    {p.name} {p.trial_days ? `(prueba ${p.trial_days} días)` : ''}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Periodo">
              <select value={interval} onChange={(e) => setInterval(e.target.value as 'month' | 'year')}>
                <option value="month">Mensual</option>
                <option value="year">Anual</option>
              </select>
            </Field>
            <Field label="Tarjeta (pasarela simulada)">
              <select value={card} onChange={(e) => setCard(e.target.value)}>
                {TEST_CARDS.map((c) => <option key={c.token} value={c.token}>{c.label}</option>)}
              </select>
            </Field>
            <ErrorNotice error={subscribe.error} />
            <button type="submit" disabled={subscribe.busy} data-testid="subscribe">Contratar</button>
          </form>
        </Card>
      )}

      <Card title="Método de pago">
        <form onSubmit={(e) => { e.preventDefault(); void saveCard.run() }} className="inline-form">
          <Field label="Tarjeta de prueba">
            <select value={newCard} onChange={(e) => setNewCard(e.target.value)} data-testid="card-select">
              {TEST_CARDS.map((c) => <option key={c.token} value={c.token}>{c.label}</option>)}
            </select>
          </Field>
          <button type="submit" disabled={saveCard.busy} data-testid="card-save">Guardar</button>
        </form>
        <ErrorNotice error={saveCard.error} />
        {cardSaved && <p className="notice notice-ok" role="status" data-testid="card-saved">Método de pago guardado.</p>}
      </Card>

      <Card title="Facturas">
        {invoices.loading && <Loading />}
        <ErrorNotice error={invoices.error ?? pay.error ?? pdf.error} />
        {invoices.data?.length === 0 && <Empty>Aún no hay facturas (la prueba no factura).</Empty>}
        <div className="table-wrap">
          <table data-testid="invoices">
            <thead><tr><th>Número</th><th>Estado</th><th>Total</th><th>Emitida</th><th /></tr></thead>
            <tbody>
              {invoices.data?.map((i) => (
                <tr key={i.id} data-status={i.status}>
                  <td>{i.number ?? '—'}</td>
                  <td><span className="tag">{i.status}</span></td>
                  <td>{formatMoney(i.total_minor, i.currency)}</td>
                  <td>{formatDate(i.issued_at)}</td>
                  <td className="row">
                    <button type="button" className="ghost" onClick={() => void pdf.run(i.id, i.number)}>PDF</button>
                    {i.status === 'open' && <button type="button" onClick={() => void pay.run(i.id)} disabled={pay.busy} data-testid={`pay-${i.id}`}>Pagar</button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  )
}
