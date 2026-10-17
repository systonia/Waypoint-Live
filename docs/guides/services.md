---
title: Services
description: "Inject the app's services into components, keep state per browser tab with #[Scoped], and the rule that an app-wide service may not inject a scoped one."
order: 60
---

A component reaches the rest of the app through services. They come in through Waypoint's `#[Inject]` attribute, the same way as in a controller, and the component calls them directly: a database query, a mailer or a cache is one method call away.

## App-wide services

By default a service is one instance for the whole app, shared by every component of every tab and every user. The bakery's repositories are such services, from `src/Repositories/Products.php`:

```php
/** The range from Postgres. Every call waits only for its own query: other requests of the worker go on meanwhile. */
final class Products
{
    #[Inject]
    private Postgres $db;

    /** @return list<Product> */
    public function all(): array
    {
        return array_map(fn (array $row) => Product::of(new Row($row)), $this->db->query('SELECT ' . self::COLUMNS . ' FROM products p ORDER BY p.position, p.id'));
    }

    public function byId(int $id): ?Product
    {
        $row = $this->db->first('SELECT ' . self::COLUMNS . ' FROM products p WHERE p.id = $1', [$id]);
        return $row === null ? null : Product::of(new Row($row));
    }
}
```

```php
#[Inject]
private Products $products;
```

`#[Inject]` properties may be private. They are set right after the component is created, before its inputs (see [Lifecycle](components.md#lifecycle)). The services come from the app's container: a class whose constructor needs no arguments is created on first use, one that needs arguments must be in the container already: registered by a plugin, set by the app (the bakery's `app.php` puts its Postgres pool there with `$app->getContainer()->set(Postgres::connect(...))`), or built by Waypoint's discovery because a controller injects it.

An app-wide service is the place for state that all tabs share, such as the stock or the open orders. When it changes, other tabs learn about it through a [broadcast](push.md#changes-for-every-tab).

## #[Scoped]: one instance per tab

[`#[Scoped]`](../reference/Waypoint/Live/Scoped.md) on a service class makes it one instance per *circuit*, that is per browser tab. The bakery's cart, shortened from `src/Services/Cart.php`:

```php
/**
 * The cart of one browser tab, shared by every component of the tab: the badge in the header, the buttons on
 * the cards, the cart page. It is kept in Postgres under the browser's cart cookie, so it is still there on the
 * next page, which is a new tab circuit.
 */
#[Scoped]
final class Cart
{
    #[Inject]
    private Connection $connection;

    #[Inject]
    private Carts $carts;

    /** @var array<int, CartLine>|null product id => line, loaded on first use */
    private ?array $lines = null;

    /** @return array<int, CartLine> product id => line */
    public function lines(): array
    {
        $id = $this->id();
        return $this->lines ??= $id === null ? [] : $this->carts->lines($id);
    }

    public function quantity(int $product): int
    {
        return $this->lines()[$product]->quantity ?? 0;
    }

    /** Adds one more, as long as the bakery has it. */
    public function add(Product $product): bool
    {
        return $this->set($product, $this->quantity($product->id) + 1);
    }

    public function count(): int
    {
        return array_sum($this->quantities());
    }

    // set(), total(), quantities(), clear()

    private function id(): ?string
    {
        $id = $this->connection->request->cookies[CartCookie::NAME] ?? '';
        return CartCookie::valid($id) ? $id : null;
    }
}
```

Separate components share it. [`AddToCart`](components.md#a-component-with-state) on every product card adds to it; the badge in the header shows it, from `src/Components/CartBadge.php`:

```php
/** The cart link in the header: it counts along whenever another component of the tab changes the cart. */
#[Component]
final class CartBadge
{
    #[Inject]
    private Cart $cart;

    #[Inject]
    private Translator $i18n;
}
```

```html
<!-- src/Components/CartBadge.Template.php -->
<a href="/cart" class="inline-flex items-center gap-2 rounded-full bg-amber-100 px-4 py-2 font-medium text-amber-900 hover:bg-amber-200">
    {{ $this->i18n->t('Cart') }}
    <span class="rounded-full bg-amber-900 px-2 text-sm text-amber-50">{{ $this->cart->count() }}</span>
</a>
```

The layout places the badge (`views/_Layout.php`), the catalog one `AddToCart` per product:

```html
<nav class="ml-auto flex items-center gap-3 text-sm">
    <app-cart-badge></app-cart-badge>
</nav>
```

A click on **Add to cart** changes the tab's `Cart`. After every action all top-level components of the tab render, and those whose HTML changed are sent, so the badge counts up without either component knowing about the other. The cart page has two more components on the same `Cart`, `CartEditor` and `Checkout`.

What holds for a scoped service:

- **Every component of the tab gets the same instance**: top-level ones, children, and other scoped services of the tab that inject it.
- **It lives as long as the circuit**, reconnects included ([Reconnects](reconnect.md)), and goes with it. It does not survive a page reload, which is a new circuit: keep a cart that must last in a database or a session. The bakery's cart loads its lines from Postgres, under the browser's cart cookie, so the next page finds them again.
- **It needs a constructor without required arguments.** Its own `#[Inject]` properties are set when it is created: app-wide services from the container (`Carts`), scoped ones from the same tab (`Connection`).
- **During the page's first render** a component gets a scoped service that belongs to that render only; the connected tab gets a fresh one. State a scoped service collects during the page render is not carried over.

[`Connection`](connection.md) is a scoped service that Live brings: the tab's WebSocket connection and the request it came with. The cart reads its cookie from it.

## An app-wide service may not inject a scoped one

An app-wide service is created once and then shared by all tabs and users. If it injected a `#[Scoped]` service, it would get the instance of whichever tab created it first, and every other user would see that tab's cart. Live refuses this:

```php
<?php
// src/Services/Reservations.php

namespace Bakery\Services;

use Waypoint\Attributes\Inject;

/** Wrong: one Reservations for the whole app, so its Cart would be every customer's cart. */
final class Reservations
{
    #[Inject]
    public Cart $cart;
}
```

A component that injects `Reservations` fails to be created, with the chain in the message:

```
Bakery\Services\Reservations injects #[Scoped] Bakery\Services\Cart: an app-wide service would share it between all tabs.
Make Bakery\Services\Reservations #[Scoped] too, or inject Bakery\Services\Cart where it is used
```

The check follows `#[Inject]` properties through any number of app-wide services in between. There are two fixes:

- **Make the service scoped too**: `#[Scoped] final class Reservations`. Then each tab has its own `Reservations` with its own `Cart`.
- **Pass what it needs in**: inject `Cart` into the component and hand its content to the app-wide service, as the bakery's `Checkout` component does with `$this->orders->place($this->cart->quantities(), ...)`.

The other direction is fine: a scoped service may inject app-wide ones.

## Fakes in tests

[`Tab::with()`](../reference/Waypoint/Live/Testing/Tab.md#with) puts a service into a test's tab in place of the `#[Scoped]` class it is or extends, before the first component is mounted. For a scoped `Wishlist` that is not final:

```php
$tab = Tab::for($app)->with(new class extends Wishlist {
    public array $products = ['croissant', 'pretzel'];
});
$tab->mount('app-wishlist-badge')->assertSee('Saved (2)');
```

Anything that is no `#[Scoped]` service and extends none is refused: the app's services come from the app.

The bakery's `Cart` is final and needs no fake: its tests give the tab a request with a cart cookie, and the cart works on the test database ([Testing](testing.md#users-and-requests)).

See also: [`Scoped`](../reference/Waypoint/Live/Scoped.md), [The connection and the user](connection.md), [Timers, broadcasts and server push](push.md).
