import { type ReactNode, useEffect } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { queryClient } from './queryClient'
import { useAuth } from '@/features/auth/authStore'

export function Providers({ children }: { children: ReactNode }) {
  const restore = useAuth((state) => state.restore)

  useEffect(() => {
    void restore()
  }, [restore])

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
}
