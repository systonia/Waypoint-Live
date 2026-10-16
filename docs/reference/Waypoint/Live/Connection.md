---
title: "Connection"
description: "The browser connection of a circuit, for a component or a #[Scoped] service that injects it: the request the WebSocket came in with (its cookies, and $request->jwt as the app's middlewares left it), and a way to send the browser to another page."
order: 100
generated: true
---

`Waypoint\Live\Connection` · final class · namespace [`Waypoint\Live`](index.md)

```php
final class Connection
```

The browser connection of a circuit, for a component or a #[Scoped] service that injects it: the request the
WebSocket came in with (its cookies, and $request->jwt as the app's middlewares left it), and a way to send
the browser to another page. After a reconnect, $request is the new connection's.

## Properties

### $request

```php
public private(set) \Waypoint\Http\Request $request;
```

**Type** `Request`

The request the WebSocket came in with

## Methods

### navigate()

```php
public function navigate(string $url): void
```

After this action, the browser loads $url: a path on this site ("/admin"). It counts even if the action
throws afterwards, so a check can send a logged-out user to the login and stop the action. Not during the
page's first render, only from actions and timers.

**Parameters**

- `$url` `string`
