import type { ButtonHTMLAttributes, ReactNode } from 'react'

function cx(...classes: (string | false | null | undefined)[]): string {
  return classes.filter(Boolean).join(' ')
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'success'
type ButtonSize = 'sm' | 'md' | 'lg' | 'display'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: ButtonSize
  loading?: boolean
}

const variants: Record<ButtonVariant, string> = {
  primary: 'bg-brand-500 text-white hover:bg-brand-600 active:bg-brand-600',
  secondary: 'bg-ink-100 text-ink-900 hover:bg-ink-200 active:bg-ink-200',
  ghost: 'bg-transparent text-ink-700 hover:bg-ink-100',
  danger: 'bg-status-late text-white hover:brightness-95',
  success: 'bg-status-ready text-white hover:brightness-95',
}

const sizes: Record<ButtonSize, string> = {
  sm: 'h-9 px-3 text-sm',
  // 48px minimum: a waiter taps this while walking.
  md: 'h-12 px-4 text-base',
  lg: 'h-14 px-6 text-lg',
  // 64px: a cook taps this with the back of a knuckle.
  display: 'h-16 px-6 text-xl font-semibold',
}

export function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  className,
  children,
  disabled,
  ...rest
}: ButtonProps) {
  return (
    <button
      {...rest}
      disabled={disabled || loading}
      className={cx(
        'inline-flex items-center justify-center gap-2 rounded-xl font-medium transition',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
        'disabled:cursor-not-allowed disabled:opacity-50',
        variants[variant],
        sizes[size],
        className,
      )}
    >
      {loading && (
        <span
          aria-hidden
          className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent"
        />
      )}
      {children}
    </button>
  )
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cx('rounded-2xl border border-ink-200/70 bg-white p-4 shadow-sm', className)}>
      {children}
    </div>
  )
}

type Tone = 'idle' | 'active' | 'warning' | 'ready' | 'late'

const tones: Record<Tone, string> = {
  idle: 'bg-ink-100 text-ink-600',
  active: 'bg-status-active/12 text-status-active',
  warning: 'bg-status-warning/15 text-status-warning',
  ready: 'bg-status-ready/12 text-status-ready',
  late: 'bg-status-late/12 text-status-late',
}

export function Badge({ tone = 'idle', children }: { tone?: Tone; children: ReactNode }) {
  return (
    <span
      className={cx(
        'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold tracking-wide',
        tones[tone],
      )}
    >
      {children}
    </span>
  )
}

export function Spinner({ label = 'Loading' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-3 p-8 text-ink-400" role="status">
      <span className="size-5 animate-spin rounded-full border-2 border-current border-t-transparent" />
      <span className="text-sm">{label}</span>
    </div>
  )
}

export function EmptyState({ title, hint }: { title: string; hint?: string }) {
  return (
    <div className="rounded-2xl border border-dashed border-ink-200 p-10 text-center">
      <p className="font-medium text-ink-700">{title}</p>
      {hint && <p className="mt-1 text-sm text-ink-400">{hint}</p>}
    </div>
  )
}

export function ErrorNotice({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const message = error instanceof Error ? error.message : 'Something went wrong.'

  return (
    <div className="rounded-2xl border border-status-late/30 bg-status-late/5 p-4">
      <p className="font-medium text-status-late">{message}</p>
      {onRetry && (
        <Button variant="secondary" size="sm" className="mt-3" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  )
}

/**
 * The connection indicator.
 *
 * Present on every live screen on purpose: staff must be able to tell at a
 * glance whether what they are looking at is current. A silently stale kitchen
 * display is worse than an obviously broken one.
 */
export function ConnectionPill({ state }: { state: 'connecting' | 'connected' | 'disconnected' }) {
  const config = {
    connected: { tone: 'ready' as Tone, label: 'Live' },
    connecting: { tone: 'warning' as Tone, label: 'Reconnecting' },
    disconnected: { tone: 'late' as Tone, label: 'Offline — refreshing every 15s' },
  }[state]

  return <Badge tone={config.tone}>{config.label}</Badge>
}

export { cx }
