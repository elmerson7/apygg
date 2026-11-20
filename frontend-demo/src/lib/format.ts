// El dinero viaja como entero en la unidad menor (centavos) + moneda ISO 4217: nunca float (docs/money.md).
const ZERO_DECIMALS = new Set(['JPY', 'CLP', 'PYG', 'COP', 'KRW', 'VND'])

export function formatMoney(minor: number, currency: string, locale = 'es'): string {
  const decimals = ZERO_DECIMALS.has(currency) ? 0 : 2
  const amount = minor / 10 ** decimals
  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency, minimumFractionDigits: decimals }).format(amount)
  } catch {
    return `${amount.toFixed(decimals)} ${currency}`
  }
}

export function formatDate(value: string | null | undefined, locale = 'es'): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' })
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB']
  let value = bytes / 1024
  let i = 0
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024
    i++
  }
  return `${value.toFixed(1)} ${units[i]}`
}

/** SHA-256 hexadecimal de un archivo (la subida presignada lo pide para verificar integridad). */
export async function sha256Hex(data: ArrayBuffer): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', data)
  return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('')
}

/** Clave de idempotencia nueva: una por intención del usuario (no por reintento). */
export function newIdempotencyKey(prefix = 'demo'): string {
  return `${prefix}-${crypto.randomUUID()}`
}
