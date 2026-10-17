---
title: The connection and the user
description: Read the request the WebSocket came in with, find the user from its JWT or cookies, and check the user in every component.
order: 70
---

The browser's WebSocket to `/_live` is a normal Waypoint route: the app's middlewares and guards run on its upgrade request, the JWT middleware fills `$request->jwt`, cookies arrive as usual. It is no API operation, so the OpenAPI document leaves it out. [`Connection`](../reference/Waypoint/Live/Connection.md) hands that request to the components of the tab.

## Connection

`Connection` is a `#[Scoped]` service that Live creates for every tab. A component or a scoped service injects it:

```php
#[Inject]
private Connection $connection;
```

- `$this->connection->request` is the `Waypoint\Http\Request` the WebSocket came in with: its headers, its cookies, its IP, and `jwt` as the app's middlewares left it. After a reconnect it is the new connection's request.
- `$this->connection->navigate('/path')` sends the browser to another page once the action is done ([Navigation](navigation.md)).

A browser's WebSocket sends the site's cookies but no `Authorization` header. So for `$request->jwt` to be set on `/_live`, the app's JWT must come from a cookie: Waypoint's JWT middleware reads the cookie named by `JWTOptions::$cookieName` when there is no header. An app that keeps its login in a session cookie reads that cookie from `$request->cookies` instead.

During the page's first render, before any WebSocket exists, `request` is the page's own request, so the HTML a user without JavaScript sees is rendered for the right user too.

## Every component checks the user itself

The guards of a page's route **do not protect the components on that page**. The WebSocket route `/_live` is one route for all components, and any browser that reaches it can mount any listed component with any inputs, whatever page it pretends to be on. A component whose data or actions are not for everyone checks the user itself, from its `Connection`:

- in what its template shows, and
- in every action.

An invoice list that shows a user their own invoices and lets them mark one as paid:

```php
<?php
// src/InvoiceList.php

namespace App;

use Waypoint\Attributes\Inject;
use Waypoint\Exceptions\ForbiddenException;
use Waypoint\Live\{Component, Connection};

#[Component]
final class InvoiceList
{
    #[Inject]
    private Connection $connection;

    #[Inject]
    private Invoices $invoices;

    public function markPaid(Invoice $invoice): void
    {
        // The template only offers the user's own invoices, but every action checks again
        if ($invoice->ownerId !== $this->userId()) {
            throw new ForbiddenException('Not your invoice');
        }
        $invoice->paid = true;
    }

    /** The user the WebSocket connected as, from the JWT the app's middlewares checked; null for a guest. */
    private function userId(): ?int
    {
        $sub = $this->connection->request->jwt['sub'] ?? null;
        return is_int($sub) ? $sub : null;
    }
}
```

```html
<!-- src/InvoiceList.Template.php -->
@if ($this->userId() === null)
    <p><a href="/login">Log in</a> to see your invoices.</p>
@else
    <ul>
    @foreach ($this->invoices->of($this->userId()) as $invoice)
        <li data-key="{{ $invoice->id }}">
            {{ $invoice->number }}
            @if ($invoice->paid)
                paid
            @else
                <button data-on-click="markPaid($invoice)">Mark as paid</button>
            @endif
        </li>
    @endforeach
    </ul>
@endif
```

The user comes from the request, never from an input: `#[Input]` values come back from the browser when it connects and can be anything ([Security model](security.md)). An `#[Input] public int $userId` would let anyone read anyone's invoices.

The `markPaid($invoice)` call is bound on the server, so the browser can only click invoices that the template showed to this user. The check inside the action still matters: it keeps the rule in one place, next to the change, and it holds even if the template changes later.

## The JWT is from the time of connecting

`$request->jwt` is what the middlewares found when the WebSocket connected. It is not refreshed until the browser reconnects, and a WebSocket can stay open for hours. A component that must notice a logout, an expired session or a revoked token right away asks the source again on every action:

```php
public function markPaid(Invoice $invoice): void
{
    $session = $this->sessions->find($this->connection->request->cookies['session'] ?? '')
        ?? throw new ForbiddenException('Session ended');
    // ...
}
```

When the connection drops and the browser comes back, its parked components are only handed back if the new connection's `jwt` names the same `sub` as the old one. After a logout, or for another user, the components start over from the page's inputs ([Reconnects](reconnect.md#the-same-user-only)).

## Testing with a user

[`Tab::for()`](../reference/Waypoint/Live/Testing/Tab.md#for) takes the connection's request:

```php
use Waypoint\Http\Request;

$request = Request::fromParts('GET', '/_live', cookies: ['session' => 'abc']);
$request->jwt = ['sub' => 7];

$list = Tab::for($app, $request)->mount('app-invoice-list');
$list->assertSee('R-1001')->assertDontSee('R-1003');

Tab::for($app)->mount('app-invoice-list')->assertSee('to see your invoices');
```

See also: [`Connection`](../reference/Waypoint/Live/Connection.md), [Navigation](navigation.md), [Security model](security.md).
