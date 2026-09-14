import { create } from 'zustand'
import type { AuthTokens, AuthenticatedUser, LoginResponse } from '@/types/api'
import { api, setStaffTokens } from '@/services/apiClient'
import { readJson, remove, StorageKeys } from '@/lib/storage'
import { realtime } from '@/services/realtime'

interface AuthState {
  user: AuthenticatedUser | null
  tokens: AuthTokens | null
  status: 'unknown' | 'authenticated' | 'anonymous'
  login: (email: string, password: string) => Promise<void>
  loginWithCode: (employeeCode: string, pin: string, restaurantSlug: string) => Promise<void>
  logout: () => Promise<void>
  restore: () => Promise<void>
  can: (permission: string) => boolean
}

export const useAuth = create<AuthState>((set, get) => ({
  user: null,
  tokens: readJson<AuthTokens>(StorageKeys.staffTokens),
  status: 'unknown',

  login: async (email, password) => {
    const response = await api.post<LoginResponse>('/api/v1/auth/login', {
      email,
      secret: password,
    })

    setStaffTokens(response.tokens)
    set({ user: response.user, tokens: response.tokens, status: 'authenticated' })
    await realtime.connect(response.tokens.accessToken)
  },

  /** The shift path: a code and a PIN, because nobody types an email mid-service. */
  loginWithCode: async (employeeCode, pin, restaurantSlug) => {
    const response = await api.post<LoginResponse>('/api/v1/auth/login', {
      employeeCode,
      secret: pin,
      restaurantSlug,
    })

    setStaffTokens(response.tokens)
    set({ user: response.user, tokens: response.tokens, status: 'authenticated' })
    await realtime.connect(response.tokens.accessToken)
  },

  logout: async () => {
    const tokens = get().tokens

    if (tokens) {
      // Best effort: the local session ends either way.
      await api.post('/api/v1/auth/logout', { refreshToken: tokens.refreshToken }).catch(() => undefined)
    }

    setStaffTokens(null)
    remove(StorageKeys.staffTokens)
    await realtime.disconnect()
    set({ user: null, tokens: null, status: 'anonymous' })
  },

  /**
   * Called once on boot. The stored refresh token outlives the access token, so
   * a staff member who closed the app mid-shift comes back signed in.
   */
  restore: async () => {
    const tokens = readJson<AuthTokens>(StorageKeys.staffTokens)

    if (!tokens) {
      set({ status: 'anonymous' })
      return
    }

    try {
      const user = await api.get<AuthenticatedUser>('/api/v1/auth/me')
      set({ user, tokens, status: 'authenticated' })
      await realtime.connect(tokens.accessToken)
    } catch {
      setStaffTokens(null)
      set({ user: null, tokens: null, status: 'anonymous' })
    }
  },

  /**
   * Whether to render a control. The server checks the same permission on every
   * call — this only decides what is worth showing.
   */
  can: (permission) => get().user?.permissions.includes(permission) ?? false,
}))

export const Permissions = {
  ordersView: 'Orders.View',
  ordersCreate: 'Orders.Create',
  ordersCancel: 'Orders.Cancel',
  ordersServe: 'Orders.Serve',
  tablesView: 'Tables.View',
  tablesAssign: 'Tables.Assign',
  tablesTransfer: 'Tables.Transfer',
  menuView: 'Menu.View',
  menuManage: 'Menu.Manage',
  kitchenView: 'Kitchen.View',
  kitchenManage: 'Kitchen.Manage',
  barView: 'Bar.View',
  barManage: 'Bar.Manage',
  paymentsView: 'Payments.View',
  paymentsCreate: 'Payments.Create',
  reportsView: 'Reports.View',
  restaurantManage: 'Restaurant.Manage',
} as const
