# STED Restaurant OS — web

Three applications in one bundle, split by route.

| Route | Who | Auth |
|---|---|---|
| `/order/t/:token` | The diner, after scanning the table | Guest token, scoped to one session |
| `/app/*` | Staff — floor, till, reports | Staff JWT |
| `/app/kitchen`, `/app/bar` | Wall displays | Staff JWT, station permission |

```bash
npm install
npm run dev        # expects the API on :8080
npm run typecheck
npm run test
npm run build
```

## Rules this code follows

**Money is never computed here.** The cart's arithmetic exists so the screen can
react instantly; the total a guest agrees to is the one the server returned from
`/cart/price`, and the send button stays disabled until that quote arrives.

**Real-time invalidates, it never writes.** SignalR events tell the app that
something changed; the app then re-reads from the API. An event that is lost,
duplicated, or arrives out of order therefore cannot leave a screen showing
something the server never said.

**Every live screen polls as well.** Fifteen seconds on the displays, ten on the
guest tracker. A kitchen screen that silently stops updating does not look
broken — it looks like a quiet evening.

**Offline is read-only.** The menu is cached; orders and payments are
network-only. Queueing an order offline would commit a guest to a price and an
availability the server never agreed to.

**Permissions hide, they do not secure.** Every endpoint behind every screen
checks the same permission again.
