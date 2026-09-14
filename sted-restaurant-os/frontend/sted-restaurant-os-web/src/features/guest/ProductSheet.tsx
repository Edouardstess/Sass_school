import { useMemo, useState } from 'react'
import { Button } from '@/components/ui'
import { money } from '@/lib/format'
import type { ProductDto } from '@/types/api'

/**
 * Choosing one dish.
 *
 * Required groups are enforced here so the guest is not allowed to build an
 * order the kitchen would reject — but the server validates the same rules
 * again, because this check lives in a browser.
 */
export function ProductSheet({
  product,
  currency,
  onClose,
  onAdd,
}: {
  product: ProductDto
  currency: string
  onClose: () => void
  onAdd: (optionIds: string[], notes: string | undefined, quantity: number) => void
}) {
  const [selected, setSelected] = useState<Record<string, string[]>>(() => {
    const defaults: Record<string, string[]> = {}

    for (const modifier of product.modifiers) {
      const preset = modifier.options.filter((o) => o.isDefault).map((o) => o.id)
      if (preset.length > 0) defaults[modifier.id] = preset
    }

    return defaults
  })

  const [quantity, setQuantity] = useState(1)
  const [notes, setNotes] = useState('')

  const optionIds = useMemo(() => Object.values(selected).flat(), [selected])

  const unitPrice = useMemo(() => {
    const extras = product.modifiers
      .flatMap((m) => m.options)
      .filter((o) => optionIds.includes(o.id))
      .reduce((sum, o) => sum + o.priceDelta, 0)

    return product.price + extras
  }, [product, optionIds])

  const missing = product.modifiers.filter((modifier) => {
    const count = (selected[modifier.id] ?? []).length
    return modifier.isRequired && count < Math.max(1, modifier.minSelections)
  })

  function toggle(modifierId: string, optionId: string, max: number) {
    setSelected((current) => {
      const existing = current[modifierId] ?? []

      if (existing.includes(optionId)) {
        return { ...current, [modifierId]: existing.filter((id) => id !== optionId) }
      }

      // A single-choice group swaps rather than refusing: tapping a second
      // option obviously means "this one instead".
      const next = max === 1 ? [optionId] : [...existing, optionId].slice(-max)
      return { ...current, [modifierId]: next }
    })
  }

  return (
    <div className="fixed inset-0 z-30 flex flex-col justify-end bg-ink-950/50" onClick={onClose}>
      <div
        className="max-h-[92vh] overflow-y-auto rounded-t-3xl bg-white"
        onClick={(event) => event.stopPropagation()}
      >
        {product.imageUrl && (
          <img src={product.imageUrl} alt="" className="h-44 w-full object-cover" />
        )}

        <div className="p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
          <h2 className="text-lg font-semibold text-ink-900">{product.name}</h2>
          {product.description && (
            <p className="mt-1 text-sm text-ink-400">{product.description}</p>
          )}

          {product.modifiers.map((modifier) => (
            <section key={modifier.id} className="mt-5">
              <div className="mb-2 flex items-baseline justify-between">
                <h3 className="font-medium text-ink-900">{modifier.name}</h3>
                <span className="text-xs text-ink-400">
                  {modifier.isRequired ? 'Required' : 'Optional'}
                  {modifier.maxSelections > 1 && ` · up to ${modifier.maxSelections}`}
                </span>
              </div>

              <div className="space-y-1.5">
                {modifier.options.map((option) => {
                  const checked = (selected[modifier.id] ?? []).includes(option.id)

                  return (
                    <button
                      key={option.id}
                      onClick={() => toggle(modifier.id, option.id, modifier.maxSelections)}
                      className={`flex h-12 w-full items-center justify-between rounded-xl px-3 text-left transition ${
                        checked ? 'bg-brand-500/10 ring-2 ring-brand-500' : 'bg-ink-50 ring-1 ring-ink-200'
                      }`}
                    >
                      <span className="text-sm text-ink-900">{option.name}</span>
                      {option.priceDelta !== 0 && (
                        <span className="tabular text-sm text-ink-400">
                          {option.priceDelta > 0 ? '+' : ''}
                          {money(option.priceDelta, currency)}
                        </span>
                      )}
                    </button>
                  )
                })}
              </div>
            </section>
          ))}

          <label className="mt-5 block">
            <span className="mb-1.5 block text-sm font-medium text-ink-700">
              Anything to tell the kitchen?
            </span>
            <input
              value={notes}
              onChange={(event) => setNotes(event.target.value)}
              placeholder="No onion, not spicy…"
              maxLength={200}
              className="h-12 w-full rounded-xl border border-ink-200 px-3 outline-none focus:border-brand-500"
            />
          </label>

          <div className="mt-5 flex items-center gap-3">
            <div className="flex items-center rounded-xl ring-1 ring-ink-200">
              <button
                onClick={() => setQuantity((q) => Math.max(1, q - 1))}
                className="tap grid place-items-center text-xl text-ink-600"
                aria-label="Fewer"
              >
                −
              </button>
              <span className="tabular w-8 text-center font-semibold">{quantity}</span>
              <button
                onClick={() => setQuantity((q) => Math.min(99, q + 1))}
                className="tap grid place-items-center text-xl text-ink-600"
                aria-label="More"
              >
                +
              </button>
            </div>

            <Button
              size="lg"
              className="flex-1 justify-between"
              disabled={missing.length > 0}
              onClick={() => onAdd(optionIds, notes.trim() || undefined, quantity)}
            >
              <span>{missing.length > 0 ? `Choose ${missing[0].name}` : 'Add to basket'}</span>
              <span className="tabular">{money(unitPrice * quantity, currency)}</span>
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
