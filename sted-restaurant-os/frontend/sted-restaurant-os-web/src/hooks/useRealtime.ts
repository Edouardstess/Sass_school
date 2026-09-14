import { useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { realtime, type ConnectionState } from '@/services/realtime'

export function useConnectionState(): ConnectionState {
  const [state, setState] = useState<ConnectionState>(realtime.connectionState)
  useEffect(() => realtime.onStateChange(setState), [])
  return state
}

/**
 * Invalidates queries when a matching event arrives.
 *
 * The payload is deliberately ignored. It is a nudge saying "something changed
 * over here"; the answer to what it changed to comes from the API. That is what
 * makes a dropped or duplicated event harmless.
 */
export function useRealtimeInvalidation(events: string[], queryKeys: unknown[][]): void {
  const queryClient = useQueryClient()

  useEffect(() => {
    const unsubscribes = events.map((event) =>
      realtime.on(event, () => {
        for (const key of queryKeys) {
          void queryClient.invalidateQueries({ queryKey: key })
        }
      }),
    )

    return () => unsubscribes.forEach((unsubscribe) => unsubscribe())
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [events.join(','), JSON.stringify(queryKeys)])
}

/** Re-runs a callback on each named event — for sounds and badges. */
export function useRealtimeEvent(event: string, handler: (payload: unknown) => void): void {
  useEffect(() => realtime.on(event, handler), [event, handler])
}
