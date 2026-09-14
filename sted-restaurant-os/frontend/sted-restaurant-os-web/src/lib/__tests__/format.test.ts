import { describe, expect, it } from 'vitest'
import { elapsed, minutesLabel, money } from '../format'

describe('money', () => {
  it('always shows two decimals', () => {
    expect(money(1500, 'HTG')).toContain('1')
    expect(money(1500, 'HTG')).toMatch(/,00|\.00/)
  })

  it('names the currency so a screen is never ambiguous', () => {
    expect(money(10, 'HTG')).toContain('HTG')
    expect(money(10, 'USD')).toContain('USD')
  })

  it('handles zero without falling back to a dash', () => {
    expect(money(0, 'HTG')).toContain('HTG')
  })
})

describe('elapsed', () => {
  it('reads as a stopwatch, which is how a cook reads it', () => {
    expect(elapsed(0)).toBe('0:00')
    expect(elapsed(42)).toBe('0:42')
    expect(elapsed(90)).toBe('1:30')
    expect(elapsed(3_600)).toBe('60:00')
  })

  it('never shows a negative time when a clock is skewed', () => {
    expect(elapsed(-30)).toBe('0:00')
  })
})

describe('minutesLabel', () => {
  it('switches to hours once minutes stop being readable', () => {
    expect(minutesLabel(45)).toBe('45 min')
    expect(minutesLabel(75)).toBe('1 h 15 min')
  })

  it('shows a dash rather than zero when there is nothing to say', () => {
    expect(minutesLabel(undefined)).toBe('—')
    expect(minutesLabel(null)).toBe('—')
  })
})
