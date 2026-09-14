import type { ApiResponse } from '@/types/api'
import { readJson, remove, StorageKeys, writeJson } from '@/lib/storage'
import type { AuthTokens } from '@/types/api'

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code?: string,
    readonly traceId?: string,
    readonly fieldErrors?: Record<string, string[]>,
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** True when retrying the identical request could succeed. */
  get isTransient(): boolean {
    return this.status === 0 || this.status === 429 || this.status >= 500
  }
}

type TokenKind = 'staff' | 'guest'

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  /** Sent on order and payment calls so a retry cannot create a second one. */
  idempotencyKey?: string
  signal?: AbortSignal
  tokenKind?: TokenKind
}

const BASE_URL = import.meta.env.VITE_API_URL ?? ''

let refreshInFlight: Promise<AuthTokens | null> | null = null

function staffTokens(): AuthTokens | null {
  return readJson<AuthTokens>(StorageKeys.staffTokens)
}

function guestToken(): string | null {
  const session = readJson<{ guestToken: string; expiresAt: string }>(StorageKeys.guestSession)
  if (!session) return null
  return new Date(session.expiresAt) > new Date() ? session.guestToken : null
}

export function setStaffTokens(tokens: AuthTokens | null): void {
  if (tokens) writeJson(StorageKeys.staffTokens, tokens)
  else remove(StorageKeys.staffTokens)
}

/**
 * Exchanges the refresh token for a new pair.
 *
 * Deduplicated: when four queries hit a 401 at the same moment they must not
 * fire four refreshes. The server rotates on every use and treats a replayed
 * token as theft, so a burst would log the user out.
 */
async function refreshTokens(): Promise<AuthTokens | null> {
  if (refreshInFlight) return refreshInFlight

  refreshInFlight = (async () => {
    const current = staffTokens()
    if (!current) return null

    try {
      const response = await fetch(`${BASE_URL}/api/v1/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refreshToken: current.refreshToken }),
      })

      if (!response.ok) {
        setStaffTokens(null)
        return null
      }

      const payload = (await response.json()) as ApiResponse<AuthTokens>
      const next = payload.data ?? null
      setStaffTokens(next)
      return next
    } catch {
      return null
    } finally {
      refreshInFlight = null
    }
  })()

  return refreshInFlight
}

async function parse<T>(response: Response): Promise<T> {
  const text = await response.text()
  const payload = text ? (JSON.parse(text) as ApiResponse<T>) : ({ success: response.ok } as ApiResponse<T>)

  if (!response.ok || payload.success === false) {
    throw new ApiError(
      payload.message ?? `Request failed (${response.status}).`,
      response.status,
      payload.code,
      payload.traceId,
      payload.errors,
    )
  }

  return payload.data as T
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, idempotencyKey, signal, tokenKind = 'staff' } = options

  const send = async (token: string | null): Promise<Response> => {
    const headers: Record<string, string> = { Accept: 'application/json' }
    if (body !== undefined) headers['Content-Type'] = 'application/json'
    if (token) headers.Authorization = `Bearer ${token}`
    if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey

    return fetch(`${BASE_URL}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal,
    })
  }

  const token = tokenKind === 'guest' ? guestToken() : (staffTokens()?.accessToken ?? null)

  let response: Response
  try {
    response = await send(token)
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw new ApiError('Cannot reach the server.', 0)
  }

  // One silent retry after a refresh. Access tokens live fifteen minutes, so
  // this happens routinely and should never be visible to the user.
  if (response.status === 401 && tokenKind === 'staff') {
    const refreshed = await refreshTokens()
    if (refreshed) {
      response = await send(refreshed.accessToken)
    }
  }

  return parse<T>(response)
}

export const api = {
  get: <T>(path: string, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    request<T>(path, { ...options, method: 'GET' }),

  post: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    request<T>(path, { ...options, method: 'POST', body }),

  patch: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    request<T>(path, { ...options, method: 'PATCH', body }),

  delete: <T>(path: string, options?: Omit<RequestOptions, 'method' | 'body'>) =>
    request<T>(path, { ...options, method: 'DELETE' }),
}

/** A fresh idempotency key. Generated once per user action, reused on retries. */
export function newIdempotencyKey(): string {
  return crypto.randomUUID()
}
