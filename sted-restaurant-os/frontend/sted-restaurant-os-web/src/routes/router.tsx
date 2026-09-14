import { lazy, Suspense } from 'react'
import { createBrowserRouter, Navigate } from 'react-router-dom'
import { Spinner } from '@/components/ui'
import { RequirePermission } from '@/app/RequirePermission'
import { StaffLayout } from '@/app/StaffLayout'
import { Permissions } from '@/features/auth/authStore'
import { LoginPage } from '@/features/auth/LoginPage'

/*
  Three applications, one bundle, split by route.

  A diner's phone must not download the back office to look at a menu, and a
  wall-mounted kitchen screen must not download the till. Every screen below the
  guest app is lazy.
*/
const GuestApp = lazy(() =>
  import('@/features/guest/GuestApp').then((m) => ({ default: m.GuestApp })),
)
const WaiterDashboard = lazy(() =>
  import('@/features/waiter/WaiterDashboard').then((m) => ({ default: m.WaiterDashboard })),
)
const StationDisplay = lazy(() =>
  import('@/features/display/StationDisplay').then((m) => ({ default: m.StationDisplay })),
)
const CashierDashboard = lazy(() =>
  import('@/features/cashier/CashierDashboard').then((m) => ({ default: m.CashierDashboard })),
)
const AdminDashboard = lazy(() =>
  import('@/features/admin/AdminDashboard').then((m) => ({ default: m.AdminDashboard })),
)

function Lazy({ children }: { children: React.ReactNode }) {
  return <Suspense fallback={<Spinner />}>{children}</Suspense>
}

export const router = createBrowserRouter([
  // The guest app. Anonymous, reached only by scanning a table.
  {
    path: '/order/t/:token',
    element: (
      <Lazy>
        <GuestApp />
      </Lazy>
    ),
  },
  {
    path: '/order',
    element: (
      <Lazy>
        <GuestApp />
      </Lazy>
    ),
  },

  { path: '/app/login', element: <LoginPage /> },

  {
    path: '/app',
    element: (
      <RequirePermission>
        <StaffLayout />
      </RequirePermission>
    ),
    children: [
      { index: true, element: <Navigate to="/app/tables" replace /> },
      {
        path: 'tables',
        element: (
          <RequirePermission permission={Permissions.tablesView}>
            <Lazy>
              <WaiterDashboard />
            </Lazy>
          </RequirePermission>
        ),
      },
      {
        path: 'cashier',
        element: (
          <RequirePermission permission={Permissions.paymentsView}>
            <Lazy>
              <CashierDashboard />
            </Lazy>
          </RequirePermission>
        ),
      },
      {
        path: 'admin',
        element: (
          <RequirePermission permission={Permissions.reportsView}>
            <Lazy>
              <AdminDashboard />
            </Lazy>
          </RequirePermission>
        ),
      },
    ],
  },

  // Station displays run full-screen with no navigation chrome: a wall screen
  // has no user to navigate.
  {
    path: '/app/kitchen',
    element: (
      <RequirePermission permission={Permissions.kitchenView}>
        <Lazy>
          <StationDisplay station="kitchen" />
        </Lazy>
      </RequirePermission>
    ),
  },
  {
    path: '/app/bar',
    element: (
      <RequirePermission permission={Permissions.barView}>
        <Lazy>
          <StationDisplay station="bar" />
        </Lazy>
      </RequirePermission>
    ),
  },

  { path: '*', element: <Navigate to="/app" replace /> },
])
