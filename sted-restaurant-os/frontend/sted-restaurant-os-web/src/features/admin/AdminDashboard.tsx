import { useQuery } from '@tanstack/react-query'
import { api } from '@/services/apiClient'
import { Card, Spinner } from '@/components/ui'
import { money } from '@/lib/format'
import { useRealtimeInvalidation } from '@/hooks/useRealtime'
import type { AdminDashboardDto, WaiterPerformanceDto } from '@/types/api'

/** What is happening right now, and who is making it happen. */
export function AdminDashboard() {
  const dashboard = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api.get<AdminDashboardDto>('/api/v1/dashboard/admin'),
    refetchInterval: 30_000,
  })

  const waiters = useQuery({
    queryKey: ['reports', 'waiters'],
    queryFn: () => api.get<WaiterPerformanceDto[]>('/api/v1/reports/waiters'),
    refetchInterval: 60_000,
  })

  useRealtimeInvalidation(['order.created', 'payment.completed', 'order.served'], [['dashboard']])

  if (dashboard.isLoading) return <Spinner label="Loading today" />

  const data = dashboard.data!
  const peak = Math.max(1, ...data.revenueByHour.map((point) => point.revenue))

  return (
    <div className="mx-auto max-w-6xl space-y-6 p-4">
      <h1 className="text-xl font-semibold text-ink-900">Today</h1>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Revenue" value={money(data.revenueToday, data.currency)} accent />
        <Stat label="Orders" value={String(data.ordersToday)} />
        <Stat label="Average ticket" value={money(data.averageTicketToday, data.currency)} />
        <Stat label="Covers" value={String(data.guestsToday)} />
        <Stat label="Tables occupied" value={`${data.tablesOccupied} / ${data.tablesOccupied + data.tablesAvailable}`} />
        <Stat label="In the kitchen" value={String(data.ordersInKitchen)} />
        <Stat label="At the bar" value={String(data.ordersAtBar)} />
        <Stat
          label="Running late"
          value={String(data.lateTickets)}
          tone={data.lateTickets > 0 ? 'late' : undefined}
        />
      </div>

      <Card>
        <h2 className="mb-4 font-semibold text-ink-900">Revenue by hour</h2>

        {/* A plain bar chart: the shape of the service is the whole message. */}
        <div className="flex h-40 items-end gap-1">
          {data.revenueByHour.map((point) => (
            <div key={point.bucket} className="flex flex-1 flex-col items-center gap-1">
              <div
                className="w-full rounded-t bg-brand-500/80"
                style={{ height: `${Math.round((point.revenue / peak) * 100)}%` }}
                title={money(point.revenue, data.currency)}
              />
              <span className="text-[10px] text-ink-400">
                {new Date(point.bucket).getHours()}h
              </span>
            </div>
          ))}
          {data.revenueByHour.length === 0 && (
            <p className="w-full text-center text-sm text-ink-400">No sales yet today.</p>
          )}
        </div>
      </Card>

      <Card>
        <h2 className="mb-3 font-semibold text-ink-900">Waiters</h2>

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-ink-100 text-left text-ink-400">
                <th className="py-2 font-medium">Waiter</th>
                <th className="py-2 text-right font-medium">Tables</th>
                <th className="py-2 text-right font-medium">Orders</th>
                <th className="py-2 text-right font-medium">Covers</th>
                <th className="py-2 text-right font-medium">Revenue</th>
                <th className="py-2 text-right font-medium">Avg ticket</th>
                <th className="py-2 text-right font-medium">Avg table</th>
              </tr>
            </thead>
            <tbody>
              {(waiters.data ?? []).map((waiter) => (
                <tr key={waiter.waiterId} className="border-b border-ink-50">
                  <td className="py-2 font-medium text-ink-900">{waiter.waiterName}</td>
                  <td className="tabular py-2 text-right">{waiter.tablesServed}</td>
                  <td className="tabular py-2 text-right">{waiter.orderCount}</td>
                  <td className="tabular py-2 text-right">{waiter.guestCount}</td>
                  <td className="tabular py-2 text-right font-semibold">
                    {money(waiter.revenue, waiter.currency)}
                  </td>
                  <td className="tabular py-2 text-right">
                    {money(waiter.averageTicket, waiter.currency)}
                  </td>
                  <td className="tabular py-2 text-right">{waiter.averageTableMinutes} min</td>
                </tr>
              ))}
            </tbody>
          </table>

          {(waiters.data ?? []).length === 0 && (
            <p className="py-6 text-center text-sm text-ink-400">No service recorded yet.</p>
          )}
        </div>
      </Card>

      <Card>
        <h2 className="mb-3 font-semibold text-ink-900">Best sellers</h2>
        <ul className="space-y-2">
          {data.topProducts.map((product) => (
            <li key={product.productId} className="flex justify-between text-sm">
              <span className="text-ink-700">
                {product.quantitySold} × {product.productName}
              </span>
              <span className="tabular font-medium">{money(product.revenue, data.currency)}</span>
            </li>
          ))}
          {data.topProducts.length === 0 && (
            <li className="text-sm text-ink-400">Nothing sold yet today.</li>
          )}
        </ul>
      </Card>
    </div>
  )
}

function Stat({
  label,
  value,
  accent,
  tone,
}: {
  label: string
  value: string
  accent?: boolean
  tone?: 'late'
}) {
  return (
    <div
      className={`rounded-2xl p-4 ring-1 ${
        accent ? 'bg-ink-900 text-white ring-ink-900' : 'bg-white ring-ink-200/60'
      }`}
    >
      <p className={`text-xs ${accent ? 'text-ink-200' : 'text-ink-400'}`}>{label}</p>
      <p
        className={`tabular mt-1 text-2xl font-bold ${
          tone === 'late' ? 'text-status-late' : ''
        }`}
      >
        {value}
      </p>
    </div>
  )
}
