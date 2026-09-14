import {
  HubConnection,
  HubConnectionBuilder,
  HubConnectionState,
  LogLevel,
} from '@microsoft/signalr'

export type ConnectionState = 'connecting' | 'connected' | 'disconnected'

type Handler = (payload: unknown) => void

/**
 * The real-time channel.
 *
 * Deliberately thin, because it is an accelerator and not a source of truth.
 * Handlers are expected to invalidate a query, not to write the payload into a
 * cache: an event that is lost, duplicated, or arrives out of order must not be
 * able to leave a screen showing something the server never said.
 */
class RealtimeClient {
  private connection: HubConnection | null = null
  private readonly handlers = new Map<string, Set<Handler>>()
  private readonly stateListeners = new Set<(state: ConnectionState) => void>()
  private state: ConnectionState = 'disconnected'

  get connectionState(): ConnectionState {
    return this.state
  }

  async connect(accessToken: string): Promise<void> {
    if (this.connection && this.connection.state !== HubConnectionState.Disconnected) {
      return
    }

    const connection = new HubConnectionBuilder()
      .withUrl(`${import.meta.env.VITE_API_URL ?? ''}/hubs/restaurant`, {
        accessTokenFactory: () => accessToken,
      })
      // Venue wifi drops. Backing off gently and never giving up matters more
      // than reconnecting instantly: a screen that stops retrying is dead.
      .withAutomaticReconnect([0, 2000, 5000, 10000, 30000])
      .configureLogging(LogLevel.Warning)
      .build()

    connection.onreconnecting(() => this.setState('connecting'))
    connection.onreconnected(() => this.setState('connected'))
    connection.onclose(() => this.setState('disconnected'))

    for (const [event, handlers] of this.handlers) {
      connection.on(event, (payload: unknown) => handlers.forEach((handler) => handler(payload)))
    }

    this.connection = connection
    this.setState('connecting')

    try {
      await connection.start()
      this.setState('connected')
    } catch {
      this.setState('disconnected')
    }
  }

  async disconnect(): Promise<void> {
    await this.connection?.stop()
    this.connection = null
    this.setState('disconnected')
  }

  on(event: string, handler: Handler): () => void {
    let handlers = this.handlers.get(event)

    if (!handlers) {
      handlers = new Set()
      this.handlers.set(event, handlers)
      this.connection?.on(event, (payload: unknown) =>
        this.handlers.get(event)?.forEach((h) => h(payload)),
      )
    }

    handlers.add(handler)
    return () => handlers.delete(handler)
  }

  onStateChange(listener: (state: ConnectionState) => void): () => void {
    this.stateListeners.add(listener)
    listener(this.state)
    return () => this.stateListeners.delete(listener)
  }

  /** Asks the server to add this connection to a station group. */
  async watchStation(stationId: string): Promise<void> {
    if (this.connection?.state === HubConnectionState.Connected) {
      await this.connection.invoke('WatchStation', stationId)
    }
  }

  private setState(state: ConnectionState): void {
    this.state = state
    this.stateListeners.forEach((listener) => listener(state))
  }
}

export const realtime = new RealtimeClient()
