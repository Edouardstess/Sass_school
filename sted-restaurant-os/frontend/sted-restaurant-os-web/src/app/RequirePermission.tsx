import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '@/features/auth/authStore'
import { Spinner } from '@/components/ui'

/**
 * Route guard.
 *
 * This hides screens; it does not secure them. Every endpoint behind these
 * screens checks the same permission server-side, because anything decided in
 * a browser is a suggestion.
 */
export function RequirePermission({
  permission,
  children,
}: {
  permission?: string
  children: ReactNode
}) {
  const { status, can } = useAuth()
  const location = useLocation()

  if (status === 'unknown') {
    return <Spinner label="Signing you in" />
  }

  if (status === 'anonymous') {
    return <Navigate to="/app/login" state={{ from: location.pathname }} replace />
  }

  if (permission && !can(permission)) {
    return (
      <div className="mx-auto max-w-md p-8 text-center">
        <h1 className="text-lg font-semibold text-ink-900">Not available to you</h1>
        <p className="mt-2 text-sm text-ink-400">
          Your role does not include this screen. Ask a manager if you need access.
        </p>
      </div>
    )
  }

  return <>{children}</>
}
