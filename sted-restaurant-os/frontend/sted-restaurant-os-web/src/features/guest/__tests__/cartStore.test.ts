import { beforeEach, describe, expect, it } from 'vitest'
import { useCart } from '../cartStore'
import type { ProductDto } from '@/types/api'

const pizza: ProductDto = {
  id: 'p1',
  name: 'Pizza Margherita',
  price: 500,
  currency: 'HTG',
  taxRate: 0.1,
  categoryId: 'c1',
  stationId: 's1',
  stationCode: 'KITCHEN',
  preparationMinutes: 12,
  isAvailable: true,
  isActive: true,
  displayOrder: 0,
  modifiers: [
    {
      id: 'm1',
      name: 'Extras',
      isRequired: false,
      minSelections: 0,
      maxSelections: 3,
      displayOrder: 0,
      options: [
        { id: 'o1', name: 'Extra cheese', priceDelta: 50, isDefault: false, isAvailable: true, displayOrder: 0 },
        { id: 'o2', name: 'No basil', priceDelta: 0, isDefault: false, isAvailable: true, displayOrder: 1 },
      ],
    },
  ],
}

describe('cart', () => {
  beforeEach(() => {
    useCart.getState().clear()
  })

  it('adds a product and counts it', () => {
    useCart.getState().add(pizza, [], undefined, 2)

    expect(useCart.getState().lines).toHaveLength(1)
    expect(useCart.getState().count()).toBe(2)
  })

  it('merges identical lines instead of listing them twice', () => {
    useCart.getState().add(pizza, ['o1'], undefined, 1)
    useCart.getState().add(pizza, ['o1'], undefined, 2)

    expect(useCart.getState().lines).toHaveLength(1)
    expect(useCart.getState().count()).toBe(3)
  })

  /**
   * Two guests ordering the same dish differently are two kitchen instructions,
   * not one. Merging them would send the wrong food.
   */
  it('keeps the same product with different options apart', () => {
    useCart.getState().add(pizza, ['o1'], undefined, 1)
    useCart.getState().add(pizza, ['o2'], undefined, 1)

    expect(useCart.getState().lines).toHaveLength(2)
  })

  it('keeps the same options with different notes apart', () => {
    useCart.getState().add(pizza, [], 'well done', 1)
    useCart.getState().add(pizza, [], 'not too hot', 1)

    expect(useCart.getState().lines).toHaveLength(2)
  })

  it('adds modifier prices into the line', () => {
    useCart.getState().add(pizza, ['o1'], undefined, 2)

    // (500 + 50) * 2 — an indication only; the server decides the real total.
    expect(useCart.getState().estimate()).toBe(1100)
  })

  it('drops a line when its quantity reaches zero', () => {
    useCart.getState().add(pizza, [], undefined, 1)
    const key = useCart.getState().lines[0].key

    useCart.getState().setQuantity(key, 0)

    expect(useCart.getState().lines).toHaveLength(0)
  })

  it('empties completely once an order has been sent', () => {
    useCart.getState().add(pizza, [], undefined, 3)
    useCart.getState().clear()

    expect(useCart.getState().count()).toBe(0)
    expect(useCart.getState().estimate()).toBe(0)
  })
})
