import { useState, type FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { Button, Card, ErrorNotice } from '@/components/ui'
import { useAuth } from './authStore'

type Mode = 'code' | 'email'

/**
 * Two ways in.
 *
 * The employee code and PIN is the default because that is what happens
 * ninety-nine times out of a hundred: a waiter on the floor, one hand free.
 * Email and password is for the office.
 */
export function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { login, loginWithCode } = useAuth()

  const [mode, setMode] = useState<Mode>('code')
  const [employeeCode, setEmployeeCode] = useState('')
  const [pin, setPin] = useState('')
  const [restaurantSlug, setRestaurantSlug] = useState(
    () => localStorage.getItem('sted.lastSlug') ?? '',
  )
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [busy, setBusy] = useState(false)

  const destination = (location.state as { from?: string } | null)?.from ?? '/app'

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    try {
      if (mode === 'code') {
        await loginWithCode(employeeCode, pin, restaurantSlug)
        localStorage.setItem('sted.lastSlug', restaurantSlug)
      } else {
        await login(email, password)
      }

      navigate(destination, { replace: true })
    } catch (caught) {
      setError(caught)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center bg-ink-900 p-4">
      <Card className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <p className="text-xs font-semibold tracking-[0.2em] text-brand-500">STED</p>
          <h1 className="mt-1 text-xl font-semibold text-ink-900">Restaurant OS</h1>
        </div>

        <div className="mb-5 grid grid-cols-2 gap-1 rounded-xl bg-ink-100 p-1">
          <button
            type="button"
            onClick={() => setMode('code')}
            className={`h-10 rounded-lg text-sm font-medium transition ${
              mode === 'code' ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-400'
            }`}
          >
            Employee code
          </button>
          <button
            type="button"
            onClick={() => setMode('email')}
            className={`h-10 rounded-lg text-sm font-medium transition ${
              mode === 'email' ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-400'
            }`}
          >
            Email
          </button>
        </div>

        <form onSubmit={submit} className="space-y-4">
          {mode === 'code' ? (
            <>
              <Field label="Restaurant">
                <input
                  value={restaurantSlug}
                  onChange={(e) => setRestaurantSlug(e.target.value)}
                  placeholder="le-palmier"
                  autoComplete="organization"
                  className={inputClass}
                  required
                />
              </Field>
              <Field label="Employee code">
                <input
                  value={employeeCode}
                  onChange={(e) => setEmployeeCode(e.target.value.toUpperCase())}
                  placeholder="JEAN01"
                  autoCapitalize="characters"
                  className={`${inputClass} font-mono tracking-widest`}
                  required
                />
              </Field>
              <Field label="PIN">
                <input
                  value={pin}
                  onChange={(e) => setPin(e.target.value)}
                  type="password"
                  inputMode="numeric"
                  autoComplete="current-password"
                  className={`${inputClass} font-mono tracking-[0.4em]`}
                  required
                />
              </Field>
            </>
          ) : (
            <>
              <Field label="Email">
                <input
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  type="email"
                  autoComplete="username"
                  className={inputClass}
                  required
                />
              </Field>
              <Field label="Password">
                <input
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  type="password"
                  autoComplete="current-password"
                  className={inputClass}
                  required
                />
              </Field>
            </>
          )}

          {error != null && <ErrorNotice error={error} />}

          <Button type="submit" size="lg" loading={busy} className="w-full">
            Sign in
          </Button>
        </form>
      </Card>
    </div>
  )
}

const inputClass =
  'h-12 w-full rounded-xl border border-ink-200 bg-white px-3 text-base outline-none ' +
  'focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20'

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-medium text-ink-700">{label}</span>
      {children}
    </label>
  )
}
