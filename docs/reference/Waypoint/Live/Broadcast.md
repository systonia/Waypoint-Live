---
title: "Broadcast"
description: "Renders every connected tab again: for state that tabs share through an app-wide service, such as a chat."
order: 100
generated: true
---

`Waypoint\Live\Broadcast` · final class · namespace [`Waypoint\Live`](index.md)

```php
final class Broadcast
```

Renders every connected tab again: for state that tabs share through an app-wide service, such as a chat. A
tab only renders after its own actions and timers; `$broadcast->changed()` after a change reaches the others.
Inject it into a component or a service, or take it with `$app->get(Broadcast::class)`.

Under a Tenma with Tenma::publish(), the other workers render their tabs too, and with `tenma --pubsub` every
process on that Redis.

## Methods

### changed()

```php
public function changed(): void
```

Every connected tab renders, and sends the components whose HTML changed.

### changedHere()

```php
public function changedHere(): void
```

Only this worker's tabs render: in a Tenma::subscribe() callback that has just brought this worker's state
up to date. Every worker runs it, so changed() would render every tab once per worker.
