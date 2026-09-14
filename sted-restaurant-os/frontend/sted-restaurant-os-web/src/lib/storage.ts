/**
 * Browser storage that never throws.
 *
 * Private windows, cleared site data and locked-down browsers all make these
 * calls fail. A kitchen tablet losing its stored filter is a nuisance; a
 * kitchen tablet showing a white screen because localStorage threw is a stopped
 * service.
 */
export function readJson<T>(key: string): T | null {
  try {
    const raw = window.localStorage.getItem(key)
    return raw ? (JSON.parse(raw) as T) : null
  } catch {
    return null
  }
}

export function writeJson(key: string, value: unknown): void {
  try {
    window.localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // Storage is a convenience here, never a requirement.
  }
}

export function remove(key: string): void {
  try {
    window.localStorage.removeItem(key)
  } catch {
    // ignore
  }
}

export const StorageKeys = {
  staffTokens: 'sted.staff.tokens',
  guestSession: 'sted.guest.session',
  cart: 'sted.guest.cart',
} as const
