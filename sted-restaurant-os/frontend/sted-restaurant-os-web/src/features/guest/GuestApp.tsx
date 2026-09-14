import { useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge, Button, EmptyState, ErrorNotice, Spinner } from '@/components/ui'
import { money } from '@/lib/format'
import { useCart } from './cartStore'
import {
  callWaiter,
  fetchGuestMenu,
  fetchMySession,
  newIdempotencyKey,
  placeGuestOrder,
  priceCart,
  requestBill,
  resolveQrToken,
  storedGuestSession,
} from './guestApi'
import type { ProductDto } from '@/types/api'
import { ProductSheet } from './ProductSheet'
import { CartSheet } from './CartSheet'
import { OrderTracker } from './OrderTracker'

type Tab = 'menu' | 'orders'

/**
 * The guest application.
 *
 * Mobile only, one thumb, no account, no instructions. Everything that can be
 * decided for the diner is decided for them; the only choices left are what to
 * eat and when to send it.
 */
export function GuestApp() {
  const { token } = useParams<{ token: string }>()
  const queryClient = useQueryClient()
  const cart = useCart()

  const [tab, setTab] = useState<Tab>('menu')
  const [selected, setSelected] = useState<ProductDto | null>(null)
  const [cartOpen, setCartOpen] = useState(false)
  const [category, setCategory] = useState<string | null>(null)

  const session = useQuery({
    queryKey: ['guest', 'session', token],
    queryFn: async () => {
      const existing = storedGuestSession()

      // A token already in hand means this phone has scanned recently. Reuse it
      // rather than reopening a session for the same party.
      if (existing && (!token || existing.guestToken)) return existing
      if (!token) throw new Error('Scan the code on your table to start.')

      return resolveQrToken(token)
    },
    retry: false,
  })

  const menu = useQuery({
    queryKey: ['guest', 'menu'],
    queryFn: fetchGuestMenu,
    enabled: session.isSuccess,
    staleTime: 60_000,
  })

  useEffect(() => {
    if (menu.data && category === null && menu.data.categories.length > 0) {
      setCategory(menu.data.categories[0].id)
    }
  }, [menu.data, category])

  const products = useMemo(
    () => (menu.data?.products ?? []).filter((p) => !category || p.categoryId === category),
    [menu.data, category],
  )

  const currency = session.data?.currency ?? 'HTG'

  const place = useMutation({
    mutationFn: async () => {
      // One key per attempt to send. Reused if the network fails and the guest
      // taps again, which is the whole point.
      const key = newIdempotencyKey()

      return placeGuestOrder(
        cart.lines.map((line) => ({
          productId: line.productId,
          quantity: line.quantity,
          notes: line.notes,
          modifierOptionIds: line.modifierOptionIds,
        })),
        undefined,
        key,
      )
    },
    onSuccess: () => {
      cart.clear()
      setCartOpen(false)
      setTab('orders')
      void queryClient.invalidateQueries({ queryKey: ['guest', 'orders'] })
    },
  })

  if (session.isLoading) return <Spinner label="Opening your table" />
  if (session.isError) return <ErrorNotice error={session.error} />

  return (
    <div className="flex min-h-full flex-col bg-ink-50 pb-24">
      <header className="sticky top-0 z-10 border-b border-ink-200/70 bg-white/95 backdrop-blur">
        <div className="flex items-center justify-between px-4 py-3">
          <div>
            <p className="text-sm font-semibold text-ink-900">{session.data?.restaurantName}</p>
            <p className="text-xs text-ink-400">Table {session.data?.tableNumber}</p>
          </div>
          <Badge tone="active">Table service</Badge>
        </div>

        <div className="grid grid-cols-2 border-t border-ink-100">
          <TabButton active={tab === 'menu'} onClick={() => setTab('menu')}>
            Menu
          </TabButton>
          <TabButton active={tab === 'orders'} onClick={() => setTab('orders')}>
            My orders
          </TabButton>
        </div>
      </header>

      {tab === 'menu' ? (
        <main className="flex-1">
          {menu.isLoading && <Spinner label="Loading the menu" />}
          {menu.isError && <ErrorNotice error={menu.error} onRetry={() => void menu.refetch()} />}

          {menu.data && (
            <>
              <nav className="scrollbar-none flex gap-2 overflow-x-auto px-4 py-3">
                {menu.data.categories.map((c) => (
                  <button
                    key={c.id}
                    onClick={() => setCategory(c.id)}
                    className={`h-10 shrink-0 rounded-full px-4 text-sm font-medium transition ${
                      category === c.id
                        ? 'bg-ink-900 text-white'
                        : 'bg-white text-ink-600 ring-1 ring-ink-200'
                    }`}
                  >
                    {c.name}
                  </button>
                ))}
              </nav>

              <div className="space-y-2 px-4">
                {products.length === 0 && (
                  <EmptyState title="Nothing in this section right now" />
                )}

                {products.map((product) => (
                  <button
                    key={product.id}
                    onClick={() => setSelected(product)}
                    className="flex w-full items-center gap-3 rounded-2xl bg-white p-3 text-left shadow-sm ring-1 ring-ink-200/60 active:scale-[0.99]"
                  >
                    {product.imageUrl && (
                      <img
                        src={product.imageUrl}
                        alt=""
                        className="size-16 shrink-0 rounded-xl object-cover"
                        loading="lazy"
                      />
                    )}
                    <span className="min-w-0 flex-1">
                      <span className="block font-medium text-ink-900">{product.name}</span>
                      {product.description && (
                        <span className="mt-0.5 line-clamp-2 block text-sm text-ink-400">
                          {product.description}
                        </span>
                      )}
                    </span>
                    <span className="tabular shrink-0 font-semibold text-ink-900">
                      {money(product.price, currency)}
                    </span>
                  </button>
                ))}
              </div>
            </>
          )}
        </main>
      ) : (
        <OrderTracker currency={currency} />
      )}

      {/* The only persistent action. One thumb, bottom of the screen. */}
      {cart.lines.length > 0 && tab === 'menu' && (
        <div className="fixed inset-x-0 bottom-0 z-20 border-t border-ink-200 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <Button size="lg" className="w-full justify-between" onClick={() => setCartOpen(true)}>
            <span>
              View basket · {cart.count()} item{cart.count() > 1 ? 's' : ''}
            </span>
            <span className="tabular">{money(cart.estimate(), currency)}</span>
          </Button>
        </div>
      )}

      {tab === 'orders' && (
        <div className="fixed inset-x-0 bottom-0 z-20 grid grid-cols-2 gap-2 border-t border-ink-200 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <Button variant="secondary" size="lg" onClick={() => void callWaiter()}>
            Call waiter
          </Button>
          <Button variant="secondary" size="lg" onClick={() => void requestBill()}>
            Ask for the bill
          </Button>
        </div>
      )}

      {selected && (
        <ProductSheet
          product={selected}
          currency={currency}
          onClose={() => setSelected(null)}
          onAdd={(optionIds, notes, quantity) => {
            cart.add(selected, optionIds, notes, quantity)
            setSelected(null)
          }}
        />
      )}

      {cartOpen && (
        <CartSheet
          currency={currency}
          onClose={() => setCartOpen(false)}
          onConfirm={() => place.mutate()}
          submitting={place.isPending}
          error={place.error}
          quote={priceCart}
        />
      )}
    </div>
  )
}

function TabButton({
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
      className={`h-12 text-sm font-medium transition ${
        active ? 'border-b-2 border-brand-500 text-ink-900' : 'text-ink-400'
      }`}
    >
      {children}
    </button>
  )
}

export { fetchMySession }
