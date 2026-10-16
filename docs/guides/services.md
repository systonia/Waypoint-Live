---
title: Services
description: "Inject the app's services into components, keep state per browser tab with #[Scoped], and the rule that an app-wide service may not inject a scoped one."
order: 60
---

A component reaches the rest of the app through services. They come in through Waypoint's `#[Inject]` attribute, the same way as in a controller, and the component calls them directly: a database query, a mailer or a cache is one method call away.

## App-wide services

By default a service is one instance for the whole app, shared by every component of every tab and every user:

```php
<?php
// src/Catalog.php

namespace App;

/** One for the whole app: the same products for every tab. */
final class Catalog
{
    /** @return list<string> */
    public function products(): array
    {
        return ['Coffee', 'Tea', 'Cocoa'];
    }
}
```

```php
#[Inject]
private Catalog $catalog;
```

`#[Inject]` properties may be private. They are set right after the component is created, before its inputs (see [Lifecycle](components.md#lifecycle)). The services come from the app's container: a class whose constructor needs no arguments is created on first use, one that needs arguments must be in the container already: registered by a plugin, or built by Waypoint's discovery because a controller injects it.

An app-wide service is the place for state that all tabs share, such as a chat room or a cache. When it changes, other tabs learn about it through a [broadcast](push.md#changes-for-every-tab).

## #[Scoped]: one instance per tab

[`#[Scoped]`](../reference/Waypoint/Live/Scoped.md) on a service class makes it one instance per *circuit*, that is per browser tab:

```php
<?php
// src/Cart.php

namespace App;

use Waypoint\Live\Scoped;

/** One cart per browser tab: every component of the tab that injects it gets this instance. */
#[Scoped]
class Cart
{
    /** @var array<string, int> product => quantity */
    public array $items = [];

    public function add(string $product): void
    {
        $this->items[$product] = ($this->items[$product] ?? 0) + 1;
    }

    public function count(): int
    {
        return array_sum($this->items);
    }
}
```

Two separate components share it. A product list adds to the cart:

```php
<?php
// src/ProductList.php

namespace App;

use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

#[Component]
final class ProductList
{
    #[Inject]
    private Catalog $catalog;

    #[Inject]
    private Cart $cart;

    public function add(string $product): void
    {
        if (in_array($product, $this->catalog->products(), true)) {
            $this->cart->add($product);
        }
    }
}
```

```html
<!-- src/ProductList.Template.php -->
<ul>
@foreach ($this->catalog->products() as $product)
    <li data-key="{{ $product }}">
        {{ $product }} <button data-on-click="add($product)">Add to cart</button>
    </li>
@endforeach
</ul>
```

A badge in the page header shows it:

```php
<?php
// src/CartBadge.php

namespace App;

use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

#[Component]
final class CartBadge
{
    #[Inject]
    private Cart $cart;
}
```

```html
<!-- src/CartBadge.Template.php -->
<a href="/cart">Cart ({{ $this->cart->count() }})</a>
```

```html
<header><app-cart-badge></app-cart-badge></header>
<main><app-product-list></app-product-list></main>
```

A click on **Add to cart** changes the tab's `Cart`. After every action all top-level components of the tab render, and those whose HTML changed are sent, so the badge updates without either component knowing about the other. A second tab has its own cart and still shows `Cart (0)`.

What holds for a scoped service:

- **Every component of the tab gets the same instance**: top-level ones, children, and other scoped services of the tab that inject it.
- **It lives as long as the circuit**, reconnects included ([Reconnects](reconnect.md)), and goes with it. It does not survive a page reload, which is a new circuit: keep a cart that must last in a database or a session.
- **It needs a constructor without required arguments.** Its own `#[Inject]` properties are set when it is created: app-wide services from the container, scoped ones from the same tab.
- **During the page's first render** a component gets a scoped service that belongs to that render only; the connected tab gets a fresh one. State a scoped service collects during the page render is not carried over.

[`Connection`](connection.md) is a scoped service that Live brings: the tab's WebSocket connection and the request it came with.

## An app-wide service may not inject a scoped one

An app-wide service is created once and then shared by all tabs and users. If it injected a `#[Scoped]` service, it would get the instance of whichever tab created it first, and every other user would see that tab's cart. Live refuses this:

```php
<?php
// src/Checkout.php

namespace App;

use Waypoint\Attributes\Inject;

/** Wrong: one Checkout for the whole app, so its Cart would be every user's cart. */
final class Checkout
{
    #[Inject]
    public Cart $cart;
}
```

A component that injects `Checkout` fails to be created, with the chain in the message:

```
App\Checkout injects #[Scoped] App\Cart: an app-wide service would share it between all tabs.
Make App\Checkout #[Scoped] too, or inject App\Cart where it is used
```

The check follows `#[Inject]` properties through any number of app-wide services in between. There are two fixes:

- **Make the service scoped too**: `#[Scoped] final class Checkout`. Then each tab has its own `Checkout` with its own `Cart`.
- **Pass the scoped service in**: inject `Cart` into the component and hand it to the app-wide service as an argument, `$this->checkout->place($this->cart)`.

The other direction is fine: a scoped service may inject app-wide ones.

## Fakes in tests

[`Tab::with()`](../reference/Waypoint/Live/Testing/Tab.md#with) puts a service into a test's tab in place of the `#[Scoped]` class it is or extends, before the first component is mounted:

```php
$tab = Tab::for($app)->with(new class extends Cart {
    public array $items = ['Tea' => 3];
});
$tab->mount('app-cart-badge')->assertSee('Cart (3)');
```

Anything that is no `#[Scoped]` service and extends none is refused: the app's services come from the app.

See also: [`Scoped`](../reference/Waypoint/Live/Scoped.md), [The connection and the user](connection.md), [Timers, broadcasts and server push](push.md).
