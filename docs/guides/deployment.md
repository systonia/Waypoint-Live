---
title: Deployment and limits
description: Run Live in production under Tenma - workers, proxies, memory, and the limits of the proof of concept.
order: 160
---

Live runs under Tenma only: the WebSocket, the timers and the broadcasts need its event loop. Its production setup is Tenma's, described in [Tenma's documentation](https://tenma.dev); this page covers what Live adds.

## Production mode

Without `APP_ENV=development`, Live runs in production mode: no template reloading, no checks at startup, no traces, and errors reach the browser only as `data-live-error`, with the details in the log. Nothing has to be switched off by hand.

## Workers

Each Tenma worker has its own memory. A tab's circuit lives in the worker that accepted its WebSocket, for as long as the connection lasts.

- **Reconnects** come back to their components only on the same worker. With several workers a reconnect can land on another one and starts over from the page's inputs ([Reconnects](reconnect.md)).
- **App-wide services** exist once per worker. State that all tabs share belongs in Postgres or Redis, or travels to every worker with `Tenma::publish()` ([Several workers](push.md#several-workers)).
- **Broadcasts** reach every worker through `Tenma::publish()`, and every process with `tenma --pubsub redis://host`.
- **Restarts** (a deploy, `--max-requests`, a crash) drop every circuit of the worker. The browsers reconnect and their components start over.

## Behind a reverse proxy

The proxy must pass WebSocket upgrades for `/_live` and keep the headers the origin check reads: the `Host` header the browser sent, and its `Origin`. The check compares the host and port in `Origin` with `Host`; a proxy that rewrites `Host` to an internal name makes every connection fail with `403`.

The connection lasts as long as the tab is open. A proxy that closes idle connections after a timeout makes idle tabs reconnect each time, and with several workers start over; give `/_live` a long read timeout.

## Memory

Every open tab holds its components and scoped services in memory until it closes, and up to 3 minutes longer while parked (at most 100 parked circuits per worker). Rough sizing: the number of open tabs times what their components hold. Keep large data in services shared by all tabs, or load it per action, rather than in each component.

Uploaded files are in memory while the action that receives them runs. Move them and keep their path, not the `UploadedFile`.

## Limits

Live is a proof of concept, and some limits are fixed in the code for now:

| Limit | Value |
|---|---|
| Top-level components per tab | 50 |
| Files per call | 20 |
| WebSocket message, files included | Tenma's `--max-body`, 10 MB by default; files travel base64-encoded, a third larger |
| Parked circuits per worker | 100, the oldest goes first |
| Time a circuit stays parked | 3 minutes |
| Debounce of `data-on-input` | 150 ms |

And some limits of the design:

- **Every change renders the whole tab.** After any action, timer or broadcast, every top-level component of the tab renders again, children included, and only those whose HTML changed are sent. That is cheap for pages of moderate size; for a page with very many components or very long lists, page the lists and split the work. In development, Live warns when an action takes more than 50 ms or a message exceeds 100 KB.
- **Tags in pages are found by pattern.** A component's tag needs attributes in double quotes and an empty body. In a Waypoint view the rendered HTML page is searched for the tags, so a tag inside `<script>`, `<textarea>` or an HTML comment is expanded too.
- **Page inputs are JSON values.** Numbers, strings, booleans, `null` and arrays of them; a page's attributes arrive as strings.
- **A `//` comment inside `<?= ?>` breaks the template**; use `/* */`.
- **Repeated form names** other than `name[]` keep their last value.
- **An event sent while the connection is dying unnoticed is lost.**

See also: [Reconnects and parked circuits](reconnect.md), [Security model](security.md), [Timers, broadcasts and server push](push.md).
