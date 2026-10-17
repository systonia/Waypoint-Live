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

A browser's WebSocket sends the site's cookies but no `Authorization` header. So for `$request->jwt` to be set on `/_live`, the app's JWT must come from a cookie: Waypoint's JWT middleware reads the cookie named by `JWTOptions::$cookieName` when there is no header. The bakery's back office signs in that way (`$jwt->cookieName = 'session'` in `app.php`, `JWT::signIn()` in `SessionController`), so the order board on `/_live` knows who is signed in. An app that keeps its login in a session cookie reads that cookie from `$request->cookies` instead.

During the page's first render, before any WebSocket exists, `request` is the page's own request, so the HTML a user without JavaScript sees is rendered for the right user too.

What the request's middlewares keep per request in a `Waypoint\Support\FiberLocal` (the locale of an i18n plugin, the request id in the log) stays the connection's in every render of the tab: after a message from the browser, in an `#[Every]` timer, and in a render that a broadcast from another user's request started. After a reconnect it is the new connection's.

## Guards on components and actions

The guards of a page's route **do not protect the components on that page**. The WebSocket route `/_live` is one route for all components, and any browser that reaches it can mount any listed component with any inputs, whatever page it pretends to be on. The bakery's `BackofficeController` carries `#[Authenticated]`, and still its order board guards itself, with the attributes a route takes (`src/Components/OrderBoard.php`):

```php
#[Component]
#[Authenticated]
final class OrderBoard
{
    /** Bound to the order the board showed: the click moves it from the status it had then, or does nothing. */
    public function advance(Order $order): void
    {
        if ($this->orders->advance($order->id, $order->status)) {
            $this->broadcast->changed();
        }
    }
}
```

An action only the owner may take, or one behind a permission, carries the attribute on the method:

```php
#[Role('owner')]
public function cancel(Order $order): void
{
    // ...
}

#[Permissions(
    permissions: ['orders.export']
)]
public function export(): void
{
    // ...
}
```

- On the class (or a parent class) they hold for the whole component: it is checked when the component is created, in the page, on `mount` and as a child, and before every action.
- On a method they hold for that action, checked before every call. As on a route, the method's `#[Role]` and `#[Permissions]` win over the class's, and both imply `#[Authenticated]`.
- They are checked against the connection's request, `$request->jwt`, by the same rules as a route's guards: `#[Role]` reads the JWT's `role` (the bakery's staff have `staff` or `owner`), `#[Permissions]` asks the app's `PermissionProvider`. A component that fails them fails with `UnauthorizedException` or `ForbiddenException`: in the page that is the app's usual answer (a redirect to the login, a 401 or a 403), over the WebSocket the component shows `data-live-error`, and the log has the reason.
- A JWT whose `exp` has passed since the WebSocket connected counts as signed out: the next mount or call is refused, and the connection is closed. The browser connects again at once, through the app's middlewares, and gets its components back only if the new request names the same `sub` ([Reconnects](reconnect.md#the-same-user-only)).

## Checks inside the component

Where an attribute does not fit, such as a rule about which record someone may touch, the component checks it itself, from its `Connection`:

- in what its template shows, and
- in every action.

The bakery's customers do not sign in. Their cart belongs to the browser's cart cookie, and `Cart` reads it from the connection's request, never from an input (`src/Services/Cart.php`):

```php
private function id(): ?string
{
    $id = $this->connection->request->cookies[CartCookie::NAME] ?? '';
    return CartCookie::valid($id) ? $id : null;
}
```

Their order page names the order by the random code in its link, and `OrderTracker` treats that code as the customer's key: whoever has the link sees the order, no one can count through the ids. In the back office, where staff sign in, the user is `$request->jwt`:

```php
/** Who is signed in, from the JWT the app's middlewares checked; null for a guest. */
private function staff(): ?string
{
    $name = $this->connection->request->jwt['name'] ?? null;
    return is_string($name) ? $name : null;
}
```

The user comes from the request, never from an input: `#[Input]` values come back from the browser when it connects and can be anything ([Security model](security.md)). An `#[Input] public int $staffId` would let anyone act as anyone.

A bound call such as `advance($order)` can only reach orders that the template showed to this tab. A check inside the action still matters where a rule exists: it keeps the rule in one place, next to the change, and it holds even if the template changes later. The board's `advance()` moves an order only from the status the board showed, so two members of staff clicking the same card move it once.

## The JWT is from the time of connecting

`$request->jwt` is what the middlewares found when the WebSocket connected. It is not refreshed until the browser reconnects, and a WebSocket can stay open for hours. When its `exp` passes, Live closes the connection at the next mount or call, so the browser connects again with whatever login it has then (above). A component that must notice a logout or a revoked token before that asks the source again on every action:

```php
public function advance(Order $order): void
{
    $this->users->active($this->connection->request->jwt['sub'] ?? 0)
        ?? throw new ForbiddenException('Signed out');
    // ...
}
```

When the connection drops and the browser comes back, its parked components are only handed back if the new connection's `jwt` names the same `sub` as the old one. After a logout, or for another user, the components start over from the page's inputs ([Reconnects](reconnect.md#the-same-user-only)).

## Testing with a user

[`Tab::for()`](../reference/Waypoint/Live/Testing/Tab.md#for) takes the connection's request. The bakery's test case has one tab for a customer and one for staff (`tests/Support/AppTestCase.php`):

```php
/** A tab of the customer's browser, with its cart cookie. */
protected function customerTab(): Tab
{
    return Tab::for($this->app(), Request::fromParts('GET', '/_live', cookies: ['cart' => self::CART]));
}

/**
 * A tab of someone signed in to the back office.
 *
 * @param array<string, mixed> $user
 */
protected function staffTab(array $user): Tab
{
    $request = Request::fromParts('GET', '/_live');
    $request->jwt = $user;
    return Tab::for($this->app(), $request);
}
```

and `OrderBoardTest` proves that the board guards itself:

```php
public function testTheBoardIsOnlyForSignedInStaff(): void
{
    $this->expectException(UnauthorizedException::class);
    Tab::for($this->app())->mount('app-order-board');
}
```

See also: [`Connection`](../reference/Waypoint/Live/Connection.md), [Navigation](navigation.md), [Security model](security.md).
