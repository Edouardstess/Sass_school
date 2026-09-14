import { useQuery } from '@tanstack/react-query'
import { Badge, EmptyState, Spinner } from '@/components/ui'
import { clock, money } from '@/lib/format'
import { fetchMySession } from './guestApi'
import type { OrderStatus } from '@/types/api'

const labels: Record<OrderStatus, { text: string; tone: 'idle' | 'active' | 'ready' | 'late' }> = {
  Draft: { text: 'Not sent', tone: 'idle' },
  Pending: { text: 'Waiting for the waiter', tone: 'idle' },
  Confirmed: { text: 'Received', tone: 'active' },
  InPreparation: { text: 'Being prepared', tone: 'active' },
  PartiallyReady: { text: 'Partly ready', tone: 'active' },
  Ready: { text: 'Ready — on its way', tone: 'ready' },
  Served: { text: 'Served', tone: 'ready' },
  Cancelled: { text: 'Cancelled', tone: 'late' },
  Closed: { text: 'Paid', tone: 'idle' },
}

/**
 * Where the guest's order has got to.
 *
 * Polls every ten seconds rather than relying on the live channel: a phone on
 * venue wifi loses its socket constantly, and a diner staring at a status that
 * stopped moving assumes the order was lost.
 */
export function OrderTracker({ currency }: { currency: string }) {
  const session = useQuery({
    queryKey: ['guest', 'orders'],
    queryFn: fetchMySession,
    refetchInterval: 10_000,
  })

  if (session.isLoading) return <Spinner label="Loading your orders" />

  const orders = session.data?.orders ?? []

  if (orders.length === 0) {
    return (
      <div className="p-4">
        <EmptyState title="No orders yet" hint="Choose something from the menu to get started." />
      </div>
    )
  }

  return (
    <main className="flex-1 space-y-3 p-4">
      {orders.map((order) => {
        const label = labels[order.status]

        return (
          <article key={order.id} className="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-ink-200/60">
            <div className="flex items-center justify-between">
              <div>
                <p className="font-semibold text-ink-900">#{order.orderNumber}</p>
                <p className="text-xs text-ink-400">{clock(order.createdAt)}</p>
              </div>
              <Badge tone={label.tone}>{label.text}</Badge>
            </div>

            <ul className="mt-3 space-y-1 text-sm text-ink-600">
              {order.items.map((item) => (
                <li key={item.id} className="flex justify-between gap-3">
                  <span>
                    {item.quantity} × {item.productName}
                    {item.modifiers.length > 0 && (
                      <span className="text-ink-400"> — {item.modifiers.join(', ')}</span>
                    )}
                  </span>
                  <span className="tabular shrink-0">{money(item.lineTotal, currency)}</span>
                </li>
              ))}
            </ul>

            <div className="mt-3 flex justify-between border-t border-ink-100 pt-3 font-semibold">
              <span>Total</span>
              <span className="tabular">{money(order.total, currency)}</span>
            </div>
          </article>
        )
      })}

      <div className="rounded-2xl bg-ink-900 p-4 text-white">
        <div className="flex justify-between">
          <span className="text-sm text-ink-200">Table total</span>
          <span className="tabular text-lg font-semibold">
            {money(session.data?.total ?? 0, currency)}
          </span>
        </div>
      </div>
    </main>
  )
}
