/**
 * Contracts mirrored from the API DTOs.
 *
 * Hand-written for now; once the API is running these should be generated from
 * its OpenAPI document. Types written twice always drift, and here the drift
 * would be about money.
 */

export interface ApiResponse<T> {
  success: boolean
  data?: T
  message?: string
  code?: string
  errors?: Record<string, string[]>
  traceId?: string
}

export interface PagedResult<T> {
  items: T[]
  page: number
  pageSize: number
  totalCount: number
  totalPages: number
  hasNext: boolean
  hasPrevious: boolean
}

// --- auth -----------------------------------------------------------------

export interface AuthTokens {
  accessToken: string
  refreshToken: string
  accessTokenExpiresAt: string
  refreshTokenExpiresAt: string
  tokenType: string
}

export interface AuthenticatedUser {
  userId: string
  email: string
  displayName: string
  staffProfileId?: string
  restaurantId?: string
  restaurantName?: string
  roles: string[]
  permissions: string[]
}

export interface LoginResponse {
  tokens: AuthTokens
  user: AuthenticatedUser
}

export interface GuestSession {
  guestToken: string
  expiresAt: string
  restaurantId: string
  restaurantName: string
  tableId: string
  tableNumber: string
  tableSessionId: string
  currency: string
}

// --- floor ----------------------------------------------------------------

export type TableStatus = 'Available' | 'Occupied' | 'Reserved' | 'Cleaning' | 'OutOfService'

export interface TableDto {
  id: string
  number: string
  name?: string
  capacity: number
  status: TableStatus
  zoneId?: string
  zoneName?: string
  hasActiveQrCode: boolean
  currentSessionId?: string
  currentWaiterId?: string
  currentWaiterName?: string
  guestCount?: number
  sessionStartedAt?: string
  openOrderCount: number
  sessionTotal: number
  seatedMinutes?: number
}

export interface TableSessionDto {
  id: string
  tableId: string
  tableNumber: string
  sessionNumber: number
  status: 'Open' | 'Active' | 'Closed' | 'Cancelled'
  guestCount: number
  startedAt: string
  endedAt?: string
  currentWaiterId?: string
  currentWaiterName?: string
  orderCount: number
  total: number
  paid: number
  outstanding: number
  notes?: string
}

export type AssignmentAction = 'Assigned' | 'Transferred' | 'Unassigned' | 'Reassigned'

export interface ServiceAssignmentHistoryDto {
  id: string
  tableId: string
  tableNumber: string
  tableSessionId: string
  previousWaiterId?: string
  previousWaiterName?: string
  newWaiterId?: string
  newWaiterName?: string
  action: AssignmentAction
  changedBy: string
  changedByName?: string
  changedAt: string
  reason?: string
}

export interface QrCodeIssuedDto {
  qrCodeId: string
  tableId: string
  tableNumber: string
  orderUrl: string
  clearToken: string
  createdAt: string
  expiresAt?: string
}

// --- menu -----------------------------------------------------------------

export interface ModifierOptionDto {
  id: string
  name: string
  priceDelta: number
  isDefault: boolean
  isAvailable: boolean
  displayOrder: number
}

export interface ProductModifierDto {
  id: string
  name: string
  isRequired: boolean
  minSelections: number
  maxSelections: number
  displayOrder: number
  options: ModifierOptionDto[]
}

export interface ProductDto {
  id: string
  name: string
  description?: string
  imageUrl?: string
  price: number
  currency: string
  taxRate: number
  categoryId: string
  categoryName?: string
  stationId: string
  stationCode: string
  preparationMinutes: number
  isAvailable: boolean
  isActive: boolean
  displayOrder: number
  modifiers: ProductModifierDto[]
}

export interface MenuCategoryDto {
  id: string
  name: string
  description?: string
  imageUrl?: string
  displayOrder: number
  isActive: boolean
  productCount: number
}

export interface MenuDto {
  restaurantId: string
  restaurantName: string
  currency: string
  categories: MenuCategoryDto[]
  products: ProductDto[]
}

// --- ordering -------------------------------------------------------------

export type OrderStatus =
  | 'Draft'
  | 'Pending'
  | 'Confirmed'
  | 'InPreparation'
  | 'PartiallyReady'
  | 'Ready'
  | 'Served'
  | 'Cancelled'
  | 'Closed'

export type OrderSource = 'Qr' | 'Waiter' | 'Counter'

export interface OrderItemDto {
  id: string
  productId: string
  productName: string
  quantity: number
  unitPrice: number
  lineTotal: number
  stationCode: string
  status: string
  notes?: string
  modifiers: string[]
}

export interface OrderDto {
  id: string
  orderNumber: string
  tableId: string
  tableNumber: string
  tableSessionId: string
  waiterId?: string
  waiterName?: string
  status: OrderStatus
  source: OrderSource
  subtotal: number
  taxAmount: number
  discountAmount: number
  serviceChargeAmount: number
  total: number
  currency: string
  notes?: string
  createdAt: string
  confirmedAt?: string
  readyAt?: string
  servedAt?: string
  servedBy?: string
  servedByName?: string
  items: OrderItemDto[]
  waitingMinutes?: number
}

export interface CartLineRequest {
  productId: string
  quantity: number
  notes?: string
  modifierOptionIds: string[]
}

export interface CartQuoteLineDto {
  productId: string
  productName: string
  quantity: number
  unitPrice: number
  modifiersTotal: number
  lineSubtotal: number
  lineTax: number
  lineTotal: number
  modifierLabels: string[]
}

export interface CartQuoteDto {
  subtotal: number
  taxAmount: number
  serviceChargeAmount: number
  total: number
  currency: string
  lines: CartQuoteLineDto[]
  unavailable: { productId: string; productName: string; reason: string }[]
  canBeOrdered: boolean
}

// --- preparation ----------------------------------------------------------

export type TicketStatus = 'New' | 'Accepted' | 'InPreparation' | 'Ready' | 'PickedUp' | 'Cancelled'

export interface PreparationTicketItemDto {
  id: string
  productName: string
  quantity: number
  notes?: string
  modifiersSummary?: string
  status: string
}

export interface PreparationTicketDto {
  id: string
  ticketNumber: string
  orderId: string
  orderNumber: string
  stationId: string
  stationCode: string
  tableNumber: string
  waiterName?: string
  status: TicketStatus
  createdAt: string
  acceptedAt?: string
  readyAt?: string
  elapsedSeconds: number
  isLate: boolean
  isWarning: boolean
  orderNotes?: string
  items: PreparationTicketItemDto[]
}

export interface StationSnapshotDto {
  stationId: string
  stationCode: string
  serverTime: string
  new: PreparationTicketDto[]
  inPreparation: PreparationTicketDto[]
  ready: PreparationTicketDto[]
  lateCount: number
}

// --- billing --------------------------------------------------------------

export type PaymentMethod = 'Cash' | 'MonCash' | 'NatCash' | 'Stripe' | 'Card'
export type PaymentStatus = 'Pending' | 'Completed' | 'Failed' | 'Refunded' | 'Cancelled'

export interface PaymentDto {
  id: string
  orderId: string
  amount: number
  currency: string
  method: PaymentMethod
  status: PaymentStatus
  paidAt?: string
  processedBy: string
  processedByName?: string
  transactionReference?: string
}

export interface BillLineDto {
  productName: string
  quantity: number
  unitPrice: number
  lineTotal: number
}

export interface BillOrderDto {
  orderId: string
  orderNumber: string
  status: OrderStatus
  total: number
  createdAt: string
  lines: BillLineDto[]
}

export interface BillDto {
  tableSessionId: string
  tableId: string
  tableNumber: string
  guestCount: number
  waiterName?: string
  startedAt: string
  currency: string
  subtotal: number
  taxAmount: number
  discountAmount: number
  serviceChargeAmount: number
  total: number
  paid: number
  outstanding: number
  isSettled: boolean
  orders: BillOrderDto[]
  payments: PaymentDto[]
}

export interface PendingSessionDto {
  tableSessionId: string
  tableId: string
  tableNumber: string
  waiterName?: string
  guestCount: number
  startedAt: string
  orderCount: number
  total: number
  paid: number
  outstanding: number
  billRequested: boolean
}

// --- reports --------------------------------------------------------------

export interface WaiterPerformanceDto {
  waiterId: string
  waiterName: string
  employeeCode: string
  tablesServed: number
  orderCount: number
  ordersServed: number
  guestCount: number
  revenue: number
  currency: string
  averageTicket: number
  averageTableMinutes: number
  cancelledOrders: number
  transfersIn: number
  transfersOut: number
}

export interface SalesPointDto {
  bucket: string
  revenue: number
  orderCount: number
  guestCount: number
}

export interface ProductPerformanceDto {
  productId: string
  productName: string
  categoryName?: string
  stationCode: string
  quantitySold: number
  revenue: number
  orderCount: number
}

export interface AdminDashboardDto {
  currency: string
  revenueToday: number
  ordersToday: number
  averageTicketToday: number
  guestsToday: number
  tablesOccupied: number
  tablesAvailable: number
  ordersInKitchen: number
  ordersAtBar: number
  ordersReady: number
  ordersServedToday: number
  lateTickets: number
  activeWaiters: number
  revenueByHour: SalesPointDto[]
  topProducts: ProductPerformanceDto[]
}
