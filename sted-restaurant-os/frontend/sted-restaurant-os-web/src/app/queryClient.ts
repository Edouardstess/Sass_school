import { QueryClient } from '@tanstack/react-query'
import { ApiError } from '@/services/apiClient'

/**
 * Server state lives here, and only here.
 *
 * Real-time events invalidate these queries; they never write into the cache
 * directly. One source of truth, one way in.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 10_000,
      gcTime: 5 * 60_000,
      refetchOnWindowFocus: true,

      // A wrong password or a forbidden action will not become right on the
      // third attempt; retrying them just delays the error the user needs.
      retry: (failureCount, error) => {
        if (error instanceof ApiError && !error.isTransient) return false
        return failureCount < 2
      },

      retryDelay: (attempt) => Math.min(1000 * 2 ** attempt, 8000),
    },
    mutations: {
      // Never retried automatically. A repeated order or payment is exactly
      // what the idempotency key exists to prevent, and a silent retry here
      // would be the client causing the problem.
      retry: false,
    },
  },
})
