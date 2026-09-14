import { create } from 'zustand'
import { readJson, StorageKeys, writeJson } from '@/lib/storage'
import type { ProductDto } from '@/types/api'

export interface CartLine {
  /** Local id: the same product with different options is a separate line. */
  key: string
  productId: string
  productName: string
  unitPrice: number
  quantity: number
  notes?: string
  modifierOptionIds: string[]
  modifierLabels: string[]
}

interface CartState {
  lines: CartLine[]
  add: (product: ProductDto, optionIds: string[], notes: string | undefined, quantity: number) => void
  setQuantity: (key: string, quantity: number) => void
  remove: (key: string) => void
  clear: () => void
  count: () => number
  /** An indication only. The total that counts comes from the server. */
  estimate: () => number
}

function lineKey(productId: string, optionIds: string[], notes?: string): string {
  return `${productId}|${[...optionIds].sort().join(',')}|${notes ?? ''}`
}

const persisted = readJson<CartLine[]>(StorageKeys.cart) ?? []

export const useCart = create<CartState>((set, get) => ({
  lines: persisted,

  add: (product, optionIds, notes, quantity) => {
    const key = lineKey(product.id, optionIds, notes)

    const modifierLabels = product.modifiers
      .flatMap((modifier) => modifier.options)
      .filter((option) => optionIds.includes(option.id))
      .map((option) => option.name)

    const optionsTotal = product.modifiers
      .flatMap((modifier) => modifier.options)
      .filter((option) => optionIds.includes(option.id))
      .reduce((sum, option) => sum + option.priceDelta, 0)

    set((state) => {
      const existing = state.lines.find((line) => line.key === key)

      const lines = existing
        ? state.lines.map((line) =>
            line.key === key ? { ...line, quantity: line.quantity + quantity } : line,
          )
        : [
            ...state.lines,
            {
              key,
              productId: product.id,
              productName: product.name,
              unitPrice: product.price + optionsTotal,
              quantity,
              notes,
              modifierOptionIds: optionIds,
              modifierLabels,
            },
          ]

      writeJson(StorageKeys.cart, lines)
      return { lines }
    })
  },

  setQuantity: (key, quantity) =>
    set((state) => {
      const lines =
        quantity < 1
          ? state.lines.filter((line) => line.key !== key)
          : state.lines.map((line) => (line.key === key ? { ...line, quantity } : line))

      writeJson(StorageKeys.cart, lines)
      return { lines }
    }),

  remove: (key) =>
    set((state) => {
      const lines = state.lines.filter((line) => line.key !== key)
      writeJson(StorageKeys.cart, lines)
      return { lines }
    }),

  clear: () => {
    writeJson(StorageKeys.cart, [])
    set({ lines: [] })
  },

  count: () => get().lines.reduce((sum, line) => sum + line.quantity, 0),

  estimate: () => get().lines.reduce((sum, line) => sum + line.unitPrice * line.quantity, 0),
}))
