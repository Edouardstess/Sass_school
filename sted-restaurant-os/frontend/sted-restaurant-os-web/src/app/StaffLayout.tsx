import { NavLink, Outlet } from 'react-router-dom'
import { useAuth, Permissions } from '@/features/auth/authStore'
import { ConnectionPill, cx } from '@/components/ui'
import { useConnectionState } from '@/hooks/useRealtime'

/** The staff shell. Navigation shows only what this person can actually open. */
export function StaffLayout() {
  const { user, can, logout } = useAuth()
  const connection = useConnectionState()

  const links = [
    { to: '/app/tables', label: 'Tables', permission: Permissions.tablesView },
    { to: '/app/cashier', label: 'Till', permission: Permissions.paymentsView },
    { to: '/app/kitchen', label: 'Kitchen', permission: Permissions.kitchenView },
    { to: '/app/bar', label: 'Bar', permission: Permissions.barView },
    { to: '/app/admin', label: 'Reports', permission: Permissions.reportsView },
  ].filter((link) => can(link.permission))

  return (
    <div className="flex min-h-full flex-col">
      <header className="sticky top-0 z-20 border-b border-ink-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3">
          <span className="text-xs font-bold tracking-[0.2em] text-brand-500">STED</span>

          <nav className="flex flex-1 gap-1 overflow-x-auto">
            {links.map((link) => (
              <NavLink
                key={link.to}
                to={link.to}
                className={({ isActive }) =>
                  cx(
                    'tap grid shrink-0 place-items-center rounded-xl px-4 text-sm font-medium transition',
                    isActive ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-ink-100',
                  )
                }
              >
                {link.label}
              </NavLink>
            ))}
          </nav>

          <ConnectionPill state={connection} />

          <div className="hidden text-right sm:block">
            <p className="text-sm font-medium text-ink-900">{user?.displayName}</p>
            <p className="text-xs text-ink-400">{user?.restaurantName}</p>
          </div>

          <button
            onClick={() => void logout()}
            className="text-sm font-medium text-ink-400 hover:text-ink-900"
          >
            Sign out
          </button>
        </div>
      </header>

      <main className="flex-1 bg-ink-50">
        <Outlet />
      </main>
    </div>
  )
}
