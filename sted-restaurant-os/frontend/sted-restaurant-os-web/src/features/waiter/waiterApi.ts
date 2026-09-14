import { api } from '@/services/apiClient'
import type { OrderDto, PagedResult, TableDto, TableSessionDto } from '@/types/api'

export const floorApi = {
  floorPlan: (zoneId?: string) =>
    api.get<TableDto[]>(`/api/v1/tables${zoneId ? `?zoneId=${zoneId}` : ''}`),

  myTables: () => api.get<TableDto[]>('/api/v1/waiter/me/tables'),

  take: (tableId: string, guestCount: number) =>
    api.post<TableSessionDto>(`/api/v1/tables/${tableId}/take`, { guestCount }),

  transfer: (tableId: string, newWaiterId: string, reason?: string) =>
    api.post<TableSessionDto>(`/api/v1/tables/${tableId}/transfer`, { newWaiterId, reason }),

  release: (tableId: string, reason?: string) =>
    api.post<TableSessionDto>(`/api/v1/tables/${tableId}/release`, { reason }),

  closeSession: (sessionId: string) =>
    api.post<TableSessionDto>(`/api/v1/table-sessions/${sessionId}/close`),

  orders: (tableSessionId: string) =>
    api.get<PagedResult<OrderDto>>(`/api/v1/orders?tableSessionId=${tableSessionId}&pageSize=50`),

  serve: (orderId: string) => api.post<OrderDto>(`/api/v1/orders/${orderId}/serve`),
}
