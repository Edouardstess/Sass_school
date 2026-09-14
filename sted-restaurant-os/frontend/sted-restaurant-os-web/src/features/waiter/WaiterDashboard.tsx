import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge, Button, ConnectionPill, EmptyState, ErrorNotice, Spinner } from '@/components/ui'
import { minutesLabel, money } from '@/lib/format'
import { useConnectionState, useRealtimeInvalidation } from '@/hooks/useRealtime'
import { floorApi } from './waiterApi'
import type { TableDto } from '@/types/api'
import { useAuth } from '@/features/auth/authStore'

type View = 'mine' | 'all'

/**
 * The waiter's screen.
 *
 * Built for someone standing up, moving, holding something in the other hand:
 * large targets, no dialogs that must be dismissed before anything else can
 * happen, and the total on every table visible without tapping.
 */
export function WaiterDashboard() {
  const queryClient = useQueryClient()
  const connection = useConnectionState()
  const staffId = useAuth((state) => state.user?.staffProfileId)
  const [view, setView] = useState<View>('mine')

  const tables = useQuery({
    queryKey: ['tables', view],
    queryFn: () => (view === 'mine' ? floorApi.myTables() : floorApi.floorPlan()),

    // The safety net. Live events make this feel instant, but a waiter must
    // never be looking at a table that was handed over ten minutes ago.
    refetchInterval: 15_000,
  })

  useRealtimeInvalidation(
    ['table.assigned', 'table.transferred', 'table.released', 'order.created', 'order.ready'],
    [['tables', 'mine'], ['tables', 'all']],
  )

  const take = useMutation({
    mutationFn: ({ tableId, guests }: { tableId: string; guests: number }) =>
      floorApi.take(tableId, guests),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['tables'] }),
  })

  const release = useMutation({
    mutationFn: (tableId: string) => floorApi.release(tableId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['tables'] }),
  })

  if (tables.isLoading) return <Spinner label="Loading the floor" />

  const rows = tables.data ?? []

  return (
    <div className="mx-auto max-w-5xl p-4">
      <header className="mb-4 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-ink-900">
            {view === 'mine' ? 'My tables' : 'Floor plan'}
          </h1>
          <p className="text-sm text-ink-400">
            {rows.length} table{rows.length === 1 ? '' : 's'}
          </p>
        </div>
        <ConnectionPill state={connection} />
      </header>

      <div className="mb-4 grid grid-cols-2 gap-1 rounded-xl bg-ink-100 p-1">
        <Toggle active={view === 'mine'} onClick={() => setView('mine')}>
          Mine
        </Toggle>
        <Toggle active={view === 'all'} onClick={() => setView('all')}>
          All tables
        </Toggle>
      </div>

      {take.error != null && <ErrorNotice error={take.error} />}

      {rows.length === 0 ? (
        <EmptyState
          title={view === 'mine' ? 'You have no tables' : 'No tables configured'}
          hint={view === 'mine' ? 'Switch to all tables to take one.' : undefined}
        />
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {rows.map((table) => (
            <TableCard
              key={table.id}
              table={table}
              isMine={table.currentWaiterId === staffId}
              onTake={(guests) => take.mutate({ tableId: table.id, guests })}
              onRelease={() => release.mutate(table.id)}
              busy={take.isPending || release.isPending}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function TableCard({
  table,
  isMine,
  onTake,
  onRelease,
  busy,
}: {
  table: TableDto
  isMine: boolean
  onTake: (guests: number) => void
  onRelease: () => void
  busy: boolean
}) {
  const [guests, setGuests] = useState(2)

  const occupied = table.status === 'Occupied'
  const taken = Boolean(table.currentWaiterId)

  return (
    <article
      className={`rounded-2xl bg-white p-4 shadow-sm ring-1 transition ${
        isMine ? 'ring-2 ring-brand-500' : 'ring-ink-200/60'
      }`}
    >
      <div className="flex items-start justify-between">
        <div>
          <p className="text-2xl font-bold text-ink-900">{table.number}</p>
          <p className="text-xs text-ink-400">
            {table.zoneName ?? 'Main room'} · {table.capacity} seats
          </p>
        </div>

        <Badge tone={occupied ? 'active' : table.status === 'Cleaning' ? 'warning' : 'idle'}>
          {table.status}
        </Badge>
      </div>

      {occupied && (
        <dl className="mt-3 space-y-1 text-sm">
          <Line label="Waiter" value={table.currentWaiterName ?? 'Nobody yet'} />
          <Line label="Guests" value={String(table.guestCount ?? '—')} />
          <Line label="Seated" value={minutesLabel(table.seatedMinutes)} />
          <Line label="Orders" value={String(table.openOrderCount)} />
          <div className="flex justify-between border-t border-ink-100 pt-1 font-semibold">
            <dt>Total</dt>
            <dd className="tabular">{money(table.sessionTotal)}</dd>
          </div>
        </dl>
      )}

      <div className="mt-4">
        {!taken ? (
          <div className="flex gap-2">
            <select
              value={guests}
              onChange={(event) => setGuests(Number(event.target.value))}
              className="h-12 w-20 rounded-xl border border-ink-200 px-2 text-center"
              aria-label="Number of guests"
            >
              {Array.from({ length: 12 }, (_, index) => index + 1).map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
            <Button className="flex-1" loading={busy} onClick={() => onTake(guests)}>
              Take table
            </Button>
          </div>
        ) : isMine ? (
          <Button variant="secondary" className="w-full" loading={busy} onClick={onRelease}>
            Release
          </Button>
        ) : (
          <p className="text-center text-sm text-ink-400">
            Served by {table.currentWaiterName}
          </p>
        )}
      </div>
    </article>
  )
}

function Line({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between text-ink-600">
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  )
}

function Toggle({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      onClick={onClick}
      className={`h-10 rounded-lg text-sm font-medium transition ${
        active ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-400'
      }`}
    >
      {children}
    </button>
  )
}
