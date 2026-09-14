import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, newIdempotencyKey } from '@/services/apiClient'
import { Badge, Button, Card, EmptyState, ErrorNotice, Spinner } from '@/components/ui'
import { clock, minutesLabel, money } from '@/lib/format'
import { useRealtimeInvalidation } from '@/hooks/useRealtime'
import type { BillDto, PendingSessionDto } from '@/types/api'

/**
 * The till.
 *
 * Optimised for accuracy first and speed second: the amount is pre-filled with
 * what is outstanding, the running total is always visible, and the pay button
 * carries a fresh idempotency key generated when the screen opens the bill —
 * not when the button is pressed — so a double tap reuses it.
 */
export function CashierDashboard() {
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<string | null>(null)

  const pending = useQuery({
    queryKey: ['cashier', 'pending'],
    queryFn: () => api.get<PendingSessionDto[]>('/api/v1/cashier/pending-sessions'),
    refetchInterval: 15_000,
  })

  useRealtimeInvalidation(
    ['bill.requested', 'payment.completed', 'order.served', 'order.created'],
    [['cashier', 'pending']],
  )

  if (pending.isLoading) return <Spinner label="Loading the till" />

  const sessions = pending.data ?? []

  return (
    <div className="mx-auto grid max-w-6xl gap-4 p-4 lg:grid-cols-[360px_1fr]">
      <section>
        <h1 className="mb-3 text-xl font-semibold text-ink-900">
          To pay
          <span className="ml-2 text-base font-normal text-ink-400">{sessions.length}</span>
        </h1>

        {sessions.length === 0 ? (
          <EmptyState title="Nothing outstanding" hint="Every open table is settled." />
        ) : (
          <div className="space-y-2">
            {sessions.map((session) => (
              <button
                key={session.tableSessionId}
                onClick={() => setSelected(session.tableSessionId)}
                className={`w-full rounded-2xl bg-white p-3 text-left shadow-sm ring-1 transition ${
                  selected === session.tableSessionId
                    ? 'ring-2 ring-brand-500'
                    : 'ring-ink-200/60 hover:ring-ink-200'
                }`}
              >
                <div className="flex items-center justify-between">
                  <span className="text-lg font-bold text-ink-900">
                    Table {session.tableNumber}
                  </span>
                  {session.billRequested && <Badge tone="warning">Bill asked for</Badge>}
                </div>

                <p className="mt-0.5 text-xs text-ink-400">
                  {session.waiterName ?? 'No waiter'} · {session.guestCount} guests ·{' '}
                  {clock(session.startedAt)}
                </p>

                <p className="tabular mt-2 text-xl font-semibold text-ink-900">
                  {money(session.outstanding)}
                </p>
              </button>
            ))}
          </div>
        )}
      </section>

      <section>
        {selected ? (
          <BillPanel
            sessionId={selected}
            onPaid={() => {
              void queryClient.invalidateQueries({ queryKey: ['cashier'] })
            }}
          />
        ) : (
          <EmptyState title="Choose a table" hint="Its bill appears here." />
        )}
      </section>
    </div>
  )
}

function BillPanel({ sessionId, onPaid }: { sessionId: string; onPaid: () => void }) {
  const queryClient = useQueryClient()

  const bill = useQuery({
    queryKey: ['cashier', 'bill', sessionId],
    queryFn: () => api.get<BillDto>(`/api/v1/cashier/sessions/${sessionId}/bill`),
  })

  // Minted once per bill. A cashier who taps twice reuses this key, so the
  // second request returns the first payment instead of charging again.
  const [idempotencyKey, setIdempotencyKey] = useState(newIdempotencyKey)
  const [amount, setAmount] = useState<string>('')

  const pay = useMutation({
    mutationFn: () =>
      api.post(
        '/api/v1/payments',
        {
          tableSessionId: sessionId,
          amount: Number(amount || bill.data?.outstanding || 0),
          method: 'Cash',
        },
        { idempotencyKey },
      ),
    onSuccess: () => {
      setIdempotencyKey(newIdempotencyKey())
      setAmount('')
      void queryClient.invalidateQueries({ queryKey: ['cashier', 'bill', sessionId] })
      onPaid()
    },
  })

  if (bill.isLoading) return <Spinner label="Loading the bill" />
  if (bill.isError) return <ErrorNotice error={bill.error} />

  const data = bill.data!

  return (
    <Card>
      <header className="flex items-start justify-between border-b border-ink-100 pb-3">
        <div>
          <h2 className="text-xl font-semibold text-ink-900">Table {data.tableNumber}</h2>
          <p className="text-sm text-ink-400">
            {data.waiterName ?? 'No waiter'} · {data.guestCount} guests ·{' '}
            {minutesLabel(
              Math.round((Date.now() - new Date(data.startedAt).getTime()) / 60000),
            )}
          </p>
        </div>
        {data.isSettled && <Badge tone="ready">Settled</Badge>}
      </header>

      <div className="max-h-80 space-y-4 overflow-y-auto py-3">
        {data.orders.map((order) => (
          <div key={order.orderId}>
            <div className="flex justify-between text-sm font-medium text-ink-600">
              <span>#{order.orderNumber}</span>
              <span>{clock(order.createdAt)}</span>
            </div>

            <ul className="mt-1 space-y-0.5 text-sm">
              {order.lines.map((line, index) => (
                <li key={index} className="flex justify-between">
                  <span className="text-ink-700">
                    {line.quantity} × {line.productName}
                  </span>
                  <span className="tabular">{money(line.lineTotal, data.currency)}</span>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>

      <dl className="space-y-1 border-t border-ink-100 pt-3 text-sm">
        <Row label="Subtotal" value={money(data.subtotal, data.currency)} />
        {data.taxAmount > 0 && <Row label="Tax" value={money(data.taxAmount, data.currency)} />}
        {data.serviceChargeAmount > 0 && (
          <Row label="Service" value={money(data.serviceChargeAmount, data.currency)} />
        )}
        {data.discountAmount > 0 && (
          <Row label="Discount" value={`− ${money(data.discountAmount, data.currency)}`} />
        )}
        <div className="flex justify-between pt-1 text-lg font-bold">
          <dt>Total</dt>
          <dd className="tabular">{money(data.total, data.currency)}</dd>
        </div>
        {data.paid > 0 && <Row label="Already paid" value={money(data.paid, data.currency)} />}
        <div className="flex justify-between text-lg font-bold text-brand-600">
          <dt>Outstanding</dt>
          <dd className="tabular">{money(data.outstanding, data.currency)}</dd>
        </div>
      </dl>

      {pay.error != null && <ErrorNotice error={pay.error} />}

      {!data.isSettled && (
        <div className="mt-4 flex gap-2">
          <input
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
            placeholder={data.outstanding.toFixed(2)}
            inputMode="decimal"
            className="tabular h-12 w-36 rounded-xl border border-ink-200 px-3 text-right text-lg outline-none focus:border-brand-500"
            aria-label="Amount received"
          />
          <Button size="lg" className="flex-1" loading={pay.isPending} onClick={() => pay.mutate()}>
            Take cash
          </Button>
        </div>
      )}
    </Card>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between text-ink-600">
      <dt>{label}</dt>
      <dd className="tabular">{value}</dd>
    </div>
  )
}
