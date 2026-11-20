import { describe, expect, it } from 'vitest'
import { formatBytes, formatMoney, newIdempotencyKey, sha256Hex } from './format'

describe('formatMoney', () => {
  it('convierte la unidad menor sin usar floats en la API (2900 -> 29,00)', () => {
    expect(formatMoney(2900, 'USD', 'en')).toBe('$29.00')
  })
  it('respeta monedas sin decimales', () => {
    expect(formatMoney(1500, 'JPY', 'en')).toBe('¥1,500')
  })
})

describe('utilidades', () => {
  it('da tamaños legibles', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(2048)).toBe('2.0 KB')
  })
  it('genera claves de idempotencia distintas', () => {
    expect(newIdempotencyKey('a')).not.toBe(newIdempotencyKey('a'))
    expect(newIdempotencyKey('a')).toMatch(/^a-/)
  })
  it('calcula el SHA-256 de unos bytes', async () => {
    const bytes = new TextEncoder().encode('abc').buffer as ArrayBuffer
    expect(await sha256Hex(bytes)).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
  })
})
