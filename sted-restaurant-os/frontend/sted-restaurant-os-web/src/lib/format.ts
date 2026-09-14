/**
 * Display helpers.
 *
 * Money is formatted here and computed nowhere: every figure shown to a guest
 * or a cashier comes from the server. The browser's job is to render it.
 */
export function money(amount: number, currency = 'HTG'): string {
  const formatted = new Intl.NumberFormat('fr-HT', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount)

  return `${formatted} ${currency}`
}

export function clock(value: string | Date): string {
  const date = typeof value === 'string' ? new Date(value) : value
  return new Intl.DateTimeFormat('fr-HT', { hour: '2-digit', minute: '2-digit' }).format(date)
}

export function dateTime(value: string | Date): string {
  const date = typeof value === 'string' ? new Date(value) : value
  return new Intl.DateTimeFormat('fr-HT', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

/** "6:42" — how long a ticket has been waiting, as a cook reads it. */
export function elapsed(seconds: number): string {
  const safe = Math.max(0, Math.floor(seconds))
  const minutes = Math.floor(safe / 60)
  const rest = safe % 60
  return `${minutes}:${rest.toString().padStart(2, '0')}`
}

export function minutesLabel(minutes: number | undefined | null): string {
  if (minutes === undefined || minutes === null) return '—'
  if (minutes < 60) return `${minutes} min`
  const hours = Math.floor(minutes / 60)
  return `${hours} h ${minutes % 60} min`
}
