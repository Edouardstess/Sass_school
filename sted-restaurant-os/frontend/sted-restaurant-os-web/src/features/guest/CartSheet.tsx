import { useQuery } from '@tanstack/react-query'
import { Button, ErrorNotice, Spinner } from '@/components/ui'
import { money } from '@/lib/format'
import { useCart } from './cartStore'
import type { CartQuoteDto, CartLineRequest } from '@/types/api'

/**
 * The basket, and the moment of commitment.
 *
 * Note where the numbers come from: the line prices are local, for instant
 * feedback, but the total the guest agrees to is the one the server returned.
 * Sending is disabled until that quote has arrived and says the order is
 * possible.
 */
export function CartSheet({
  currency,
  onClose,
  onConfirm,
  submitting,
  error,
  quote,
}: {
  currency: string
  onClose: () => void
  onConfirm: () => void
  submitting: boolean
  error: unknown
  quote: (lines: CartLineRequest[]) => Promise<CartQuoteDto>
}) {
  const cart = useCart()

  const lines: CartLineRequest[] = cart.lines.map((line) => ({
    productId: line.productId,
    quantity: line.quantity,
    notes: line.notes,
    modifierOptionIds: line.modifierOptionIds,
  }))

  const priced = useQuery({
    queryKey: ['guest', 'quote', lines],
    queryFn: () => quote(lines),
    enabled: lines.length > 0,
    staleTime: 0,
  })

  const unavailable = priced.data?.unavailable ?? []

  return (
    <div className="fixed inset-0 z-30 flex flex-col justify-end bg-ink-950/50" onClick={onClose}>
      <div
        className="max-h-[92vh] overflow-y-auto rounded-t-3xl bg-white"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
          <h2 className="text-lg font-semibold text-ink-900">Your basket</h2>

          <div className="mt-4 space-y-3">
            {cart.lines.map((line) => (
              <div key={line.key} className="flex items-start gap-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-ink-900">{line.productName}</p>
                  {line.modifierLabels.length > 0 && (
                    <p className="text-sm text-ink-400">{line.modifierLabels.join(', ')}</p>
                  )}
                  {line.notes && <p className="text-sm italic text-ink-400">“{line.notes}”</p>}
                </div>

                <div className="flex items-center rounded-lg ring-1 ring-ink-200">
                  <button
                    onClick={() => cart.setQuantity(line.key, line.quantity - 1)}
                    className="size-9 text-ink-600"
                    aria-label="Fewer"
                  >
                    −
                  </button>
                  <span className="tabular w-7 text-center text-sm font-semibold">
                    {line.quantity}
                  </span>
                  <button
                    onClick={() => cart.setQuantity(line.key, line.quantity + 1)}
                    className="size-9 text-ink-600"
                    aria-label="More"
                  >
                    +
                  </button>
                </div>

                <span className="tabular w-24 text-right font-semibold">
                  {money(line.unitPrice * line.quantity, currency)}
                </span>
              </div>
            ))}
          </div>

          {priced.isLoading && <Spinner label="Checking prices" />}

          {unavailable.length > 0 && (
            <div className="mt-4 rounded-xl bg-status-warning/10 p-3">
              <p className="text-sm font-medium text-status-warning">
                Just sold out — remove to continue
              </p>
              <ul className="mt-1 text-sm text-ink-600">
                {unavailable.map((item) => (
                  <li key={item.productId}>• {item.productName}</li>
                ))}
              </ul>
            </div>
          )}

          {priced.data && (
            <dl className="mt-5 space-y-1.5 border-t border-ink-100 pt-4 text-sm">
              <Row label="Subtotal" value={money(priced.data.subtotal, currency)} />
              {priced.data.taxAmount > 0 && (
                <Row label="Tax" value={money(priced.data.taxAmount, currency)} />
              )}
              {priced.data.serviceChargeAmount > 0 && (
                <Row label="Service" value={money(priced.data.serviceChargeAmount, currency)} />
              )}
              <div className="flex justify-between border-t border-ink-100 pt-2 text-base font-semibold">
                <dt>Total</dt>
                <dd className="tabular">{money(priced.data.total, currency)}</dd>
              </div>
            </dl>
          )}

          {error != null && <ErrorNotice error={error} />}

          <Button
            size="lg"
            className="mt-5 w-full"
            loading={submitting}
            disabled={!priced.data?.canBeOrdered}
            onClick={onConfirm}
          >
            Send to the kitchen
          </Button>

          <p className="mt-2 text-center text-xs text-ink-400">
            Your waiter is told as soon as this is sent.
          </p>
        </div>
      </div>
    </div>
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
