import { describe, expect, it } from 'vitest'
import { ApiError, describeError, parseApiError } from './errors'

describe('parseApiError', () => {
  it('lee code, request_id y errores por campo de un 422', () => {
    const e = parseApiError(422, { message: 'Datos inválidos', code: 'validation_failed', request_id: 'r-1', errors: { email: ['Ya existe'] } })
    expect(e).toBeInstanceOf(ApiError)
    expect(e.code).toBe('validation_failed')
    expect(e.requestId).toBe('r-1')
    expect(e.fields.email).toEqual(['Ya existe'])
  })

  it('entiende RFC 7807 de un 500 (title/detail) y toma el request_id de la cabecera', () => {
    const e = parseApiError(500, { title: 'Error interno', code: 'server_error' }, new Headers({ 'X-Request-ID': 'h-9' }))
    expect(e.message).toBe('Error interno')
    expect(e.requestId).toBe('h-9')
  })

  it('reconoce el 402 con la acción sugerida (bloqueo por suscripción)', () => {
    const e = parseApiError(402, { message: 'Pago requerido', code: 'payment_required', errors: { suggested_action: { action: 'pay_invoice', method: 'POST', url: '/x' } } })
    expect(e.isPaymentRequired).toBe(true)
    expect(e.suggestedAction?.action).toBe('pay_invoice')
  })

  it('tolera cuerpos que no son JSON', () => {
    const e = parseApiError(502, '<html>bad gateway</html>')
    expect(e.code).toBe('http_error')
    expect(e.message).toBe('Error 502')
  })
})

describe('describeError', () => {
  it('describe errores ajenos a la API sin romper', () => {
    expect(describeError(new Error('boom')).message).toBe('boom')
    expect(describeError('x').code).toBe('client_error')
  })
})
