import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/services/apiClient'
import { ConnectionPill, Spinner } from '@/components/ui'
import { elapsed } from '@/lib/format'
import { useConnectionState, useRealtimeInvalidation } from '@/hooks/useRealtime'
import type { PreparationTicketDto, StationSnapshotDto } from '@/types/api'

type Station = 'kitchen' | 'bar'

/**
 * The kitchen and bar display.
 *
 * Designed for a wall screen in a hot, loud, badly lit room: dark ground, very
 * large type, 64px targets, no modal that has to be dismissed with a clean
 * hand. Colour encodes waiting time and nothing else.
 *
 * The snapshot endpoint is polled every fifteen seconds regardless of the live
 * connection. A display that quietly stops updating does not look broken — it
 * looks like a quiet evening, and the food stops going out.
 */
export function StationDisplay({ station }: { station: Station }) {
  const queryClient = useQueryClient()
  const connection = useConnectionState()
  const [now, setNow] = useState(() => Date.now())

  const snapshot = useQuery({
    queryKey: ['station', station],
    queryFn: () => api.get<StationSnapshotDto>(`/api/v1/${station}/snapshot`),
    refetchInterval: 15_000,
    refetchIntervalInBackground: true,
  })

  useRealtimeInvalidation(
    ['ticket.created', 'ticket.status_changed', 'order.cancelled'],
    [['station', station]],
  )

  // The clock ticks locally so the countdown is smooth, but it is anchored to
  // the server's time in the snapshot: cheap tablets drift by minutes.
  const offsetRef = useRef(0)

  useEffect(() => {
    if (snapshot.data) {
      offsetRef.current = Date.now() - new Date(snapshot.data.serverTime).getTime()
    }
  }, [snapshot.data])

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [])

  const act = useMutation({
    mutationFn: ({ ticketId, action }: { ticketId: string; action: string }) =>
      api.post(`/api/v1/${station}/tickets/${ticketId}/${action}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['station', station] }),
  })

  if (snapshot.isLoading) {
    return (
      <div className="display-surface grid min-h-full place-items-center">
        <Spinner label="Connecting to the pass" />
      </div>
    )
  }

  const data = snapshot.data
  const columns: { title: string; tickets: PreparationTicketDto[]; action?: string; label?: string }[] = [
    { title: 'New', tickets: data?.new ?? [], action: 'accept', label: 'Accept' },
    { title: 'In progress', tickets: data?.inPreparation ?? [], action: 'ready', label: 'Ready' },
    { title: 'Ready', tickets: data?.ready ?? [], action: 'picked-up', label: 'Collected' },
  ]

  return (
    <div className="display-surface flex min-h-full flex-col">
      <header className="flex items-center justify-between border-b border-ink-800 px-6 py-4">
        <div>
          <h1 className="text-2xl font-bold uppercase tracking-wide">
            {station === 'kitchen' ? 'Kitchen' : 'Bar'}
          </h1>
          {(data?.lateCount ?? 0) > 0 && (
            <p className="text-sm font-semibold text-status-late">
              {data?.lateCount} ticket{data?.lateCount === 1 ? '' : 's'} running late
            </p>
          )}
        </div>

        <div className="flex items-center gap-4">
          <ConnectionPill state={connection} />
          <time className="tabular text-2xl font-semibold">
            {new Date(now).toLocaleTimeString('fr-HT', { hour: '2-digit', minute: '2-digit' })}
          </time>
        </div>
      </header>

      <div className="grid flex-1 grid-cols-1 gap-px bg-ink-800 md:grid-cols-3">
        {columns.map((column) => (
          <section key={column.title} className="flex flex-col bg-ink-950">
            <h2 className="border-b border-ink-800 px-4 py-3 text-lg font-semibold uppercase tracking-wide text-ink-400">
              {column.title}
              <span className="ml-2 text-ink-600">{column.tickets.length}</span>
            </h2>

            <div className="flex-1 space-y-3 overflow-y-auto p-3">
              {column.tickets.map((ticket) => (
                <TicketCard
                  key={ticket.id}
                  ticket={ticket}
                  nowMs={now - offsetRef.current}
                  actionLabel={column.label}
                  busy={act.isPending}
                  onAction={() =>
                    column.action && act.mutate({ ticketId: ticket.id, action: column.action })
                  }
                />
              ))}

              {column.tickets.length === 0 && (
                <p className="py-10 text-center text-ink-600">Nothing here</p>
              )}
            </div>
          </section>
        ))}
      </div>
    </div>
  )
}

function TicketCard({
  ticket,
  nowMs,
  actionLabel,
  busy,
  onAction,
}: {
  ticket: PreparationTicketDto
  nowMs: number
  actionLabel?: string
  busy: boolean
  onAction: () => void
}) {
  const seconds =
    ticket.status === 'Ready' && ticket.readyAt
      ? ticket.elapsedSeconds
      : Math.max(0, Math.floor((nowMs - new Date(ticket.createdAt).getTime()) / 1000))

  // Green, amber, red. Readable across a room, and the only thing colour means.
  const border = ticket.isLate
    ? 'border-status-late'
    : ticket.isWarning
      ? 'border-status-warning'
      : 'border-status-ready'

  return (
    <article className={`rounded-xl border-l-8 bg-ink-900 p-4 ${border}`}>
      <div className="flex items-start justify-between">
        <div>
          <p className="text-xl font-bold">#{ticket.orderNumber}</p>
          <p className="text-2xl font-black tracking-tight">Table {ticket.tableNumber}</p>
          {ticket.waiterName && <p className="text-sm text-ink-400">{ticket.waiterName}</p>}
        </div>

        <p
          className={`tabular text-2xl font-bold ${
            ticket.isLate ? 'text-status-late' : ticket.isWarning ? 'text-status-warning' : 'text-ink-200'
          }`}
        >
          {elapsed(seconds)}
        </p>
      </div>

      <ul className="my-4 space-y-2">
        {ticket.items.map((item) => (
          <li key={item.id}>
            <p className="text-xl font-semibold">
              <span className="text-brand-400">{item.quantity}×</span> {item.productName}
            </p>
            {item.modifiersSummary && (
              <p className="pl-6 text-base text-status-warning">▸ {item.modifiersSummary}</p>
            )}
            {item.notes && <p className="pl-6 text-base italic text-status-warning">▸ {item.notes}</p>}
          </li>
        ))}
      </ul>

      {ticket.orderNotes && (
        <p className="mb-3 rounded-lg bg-status-warning/15 p-2 text-base text-status-warning">
          {ticket.orderNotes}
        </p>
      )}

      {actionLabel && (
        <button
          onClick={onAction}
          disabled={busy}
          className="tap-display w-full rounded-xl bg-brand-500 text-xl font-bold uppercase tracking-wide text-white transition active:scale-[0.98] disabled:opacity-50"
        >
          {actionLabel}
        </button>
      )}
    </article>
  )
}
