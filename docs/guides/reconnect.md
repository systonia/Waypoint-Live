---
title: Reconnects and parked circuits
description: What happens to a tab's components when its WebSocket drops, how the browser comes back to them, and what is lost.
order: 110
---

A tab's components and `#[Scoped]` services live in its *circuit* on the server. When the WebSocket drops (a network switch, a laptop lid, a server that restarts), the server parks the circuit for a while, and the browser tries to come back to it.

## While the connection is down

On the server:

- The circuit is **parked for 3 minutes**, with its timers paused. Nothing is sent.
- Each worker parks **at most 100** circuits; when a new one comes, the oldest parked one is closed.
- A circuit without components (every mount failed) is not parked.
- After 3 minutes the circuit is closed: timers stop, the components are dropped.

In the browser:

- The `<html>` element gets the attribute `data-live-offline`, which a stylesheet can show:

  ```css
  html[data-live-offline] body::before {
      content: "Connection lost, reconnecting…";
      display: block;
      background: #fdd;
      padding: .5rem;
  }
  ```

- `live.js` reconnects after 1, 2, 4, 8 … seconds, at most every 30 seconds, for as long as the page is open.
- Events from the time offline are queued in order.

## Coming back

On a new connection the browser sends the circuit's token. If the server still has that circuit parked, and the user is the same (below):

1. The server hands back the same components with their state, and the same scoped services.
2. `Connection::$request` becomes the new connection's request.
3. Timers start again.
4. Every top-level component renders once, since the browser missed whatever changed in between.
5. The queued events are sent, in order.

If the circuit is gone (expired, pushed out, on another worker, or the worker restarted), the server opens a new one: the components start again from the page's inputs, as on a fresh page, and **the queued events are dropped**, since they were meant for the old components.

`data-live-offline` is removed as soon as the connection is back either way.

A reload of the page is always a new circuit. The token lives only in the page's memory.

## The same user only

The token is a bearer secret for the circuit: whoever has it gets the components while they are parked. Two rules limit what that means:

- **It never leaves the page's memory.** It is not in a URL, a cookie or browser storage.
- **It works only for the same user.** A parked circuit is handed back only if the new connection's `$request->jwt` names the same `sub` as the connection it was opened with. After a logout, after a login as someone else, or for a connection without the JWT, the parked components are closed and new ones start.

An app that identifies users by something other than the JWT's `sub` (a session cookie, say) does not get this check: there the token alone decides.

## What can be lost

- **State.** A circuit lives in the memory of one worker. A reconnect that lands on another worker, a worker restart (`--watch`, `--max-requests`, a deploy) or 3 minutes offline start the components over. Keep state that must survive in a service backed by a database.
- **Events sent while the connection was dying unnoticed.** Until the browser or the server notices a dead connection (no ping yet), what the browser sends goes nowhere.
- **A race on network switches.** The server parks a circuit only once it notices that the old connection is dead. A browser that notices first may reconnect before that, find nothing parked, and start over.

See also: [Deployment and limits](deployment.md), [Security model](security.md).
