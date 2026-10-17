---
title: Components
description: "Declare a live component with #[Component], give it state and inputs, and know when it is created and dropped."
order: 10
---

A live component is a PHP class with the [`#[Component]`](../reference/Waypoint/Live/Component.md) attribute and a template. Its properties are its state, its public methods are what the browser may call (when the template names them), and its template renders the state to HTML. The object lives on the server; the browser only ever has its HTML.

## A component with state

The button on every product card of the bakery, from `src/Components/AddToCart.php`. The page passes the product's id; the cart and the products come from services:

```php
<?php

namespace Bakery\Components;

use Bakery\Repositories\Products;
use Bakery\Services\Cart;
use Waypoint\Attributes\Inject;
use Waypoint\I18n\Translator;
use Waypoint\Live\{Component, Input};

/** The button on a product card and page. The product id comes from the page, so the action looks it up again. */
#[Component]
final class AddToCart
{
    #[Input]
    public int $product = 0;

    #[Inject]
    private Cart $cart;

    #[Inject]
    private Products $products;

    #[Inject]
    private Translator $i18n;

    private ?string $notice = null;

    public function add(): void
    {
        $product = $this->products->byId($this->product);
        if ($product === null) {
            return;
        }
        $this->notice = $this->cart->add($product) ? null : $this->i18n->t('That is all we have left.');
    }
}
```

```html
<!-- src/Components/AddToCart.Template.php -->
<div class="flex flex-wrap items-center gap-3">
    <button data-on-click="add" class="rounded-lg bg-amber-800 px-4 py-2 font-medium text-white hover:bg-amber-700">
        {{ $this->i18n->t('Add to cart') }}
    </button>
    @if ($this->cart->quantity($this->product) > 0)
        <span class="text-sm text-stone-600">{{ $this->i18n->t('{count} in your cart', ['count' => $this->cart->quantity($this->product)]) }}</span>
    @endif
    @if ($this->notice !== null)
        <span role="status" class="text-sm text-red-700">{{ $this->notice }}</span>
    @endif
</div>
```

What each part does:

- **`$product`** is an [`#[Input]`](../reference/Waypoint/Live/Input.md): the catalog sets it with `<app-add-to-cart product="{{ $product->id }}"></app-add-to-cart>`. Only public, non-static, non-readonly properties with `#[Input]` can be set from outside. Its value comes back from the browser, so `add()` looks the product up again instead of trusting more than an id.
- **`$notice`** is private state. It lives as long as the component; the browser cannot read or set it.
- **`$cart`, `$products` and `$i18n`** come from the app's container through Waypoint's `#[Inject]`, as in a controller ([Services](services.md)). `Cart` is one per browser tab, so the badge in the header counts along.
- **`add()`** is an action: the template names it in `data-on-click`, so the browser may call it ([Events and actions](events.md)).
- The template calls **private members** (`$this->cart`, `$this->notice`): templates run with `$this` as the component.

After every action the template renders again and the browser receives the new HTML.

## Selector and template

Without arguments, `#[Component]` derives both names from the class:

| Class | Selector (tag) | Template |
|---|---|---|
| `Bakery\Components\AddToCart` | `app-add-to-cart` | `AddToCart.Template.php` next to `AddToCart.php` |
| `Bakery\Components\CartBadge` | `app-cart-badge` | `CartBadge.Template.php` |
| `Bakery\Components\OrderBoard` | `app-order-board` | `OrderBoard.Template.php` |

Both can be given. The template path is relative to the class's file:

```php
#[Component(
    selector: 'shop-cart',
    template: 'templates/Cart.php'
)]
final class CartSummary
{
}
```

A selector is a custom element name: lowercase, with at least one dash, and unique in the app. A missing template, a taken selector or a class without `#[Component]` stops the app when it starts, with the class's name, not later on the first page that shows it.

## Registering components

Only listed components can be created. The app lists its own in [`LivePlugin`](../reference/Waypoint/Live/LivePlugin.md), as the bakery's `app.php` does:

```php
$app->plugin(new LivePlugin([
    CartBadge::class, AddToCart::class, CartEditor::class, Checkout::class, OrderTracker::class,
    OrderBoard::class, StockEditor::class,
]));
```

A selector that is not listed is refused, whether a page, a template or a WebSocket message asks for it.

### Components of a plugin

A Waypoint plugin that brings live components of its own requires `LivePlugin` and adds them to the [`Components`](../reference/Waypoint/Live/Components.md) service in its `boot()`:

```php
use Waypoint\Live\{Components, LivePlugin};
use Waypoint\Plugin\PluginBase;

/** Stamp cards for regulars: ten coffees, the eleventh is on the bakery. */
final class StampCardPlugin extends PluginBase
{
    public function name(): string
    {
        return 'stamp-card';
    }

    public function requires(): array
    {
        return [LivePlugin::class];
    }

    public function boot(Components $components): void
    {
        $components->add(StampCard::class, StampCounter::class);
    }
}
```

`boot()` takes what it needs as parameters; Waypoint passes them from the container. Components are added at boot: once a page has rendered a component, `add()` throws.

A plugin's components name their selector with a prefix of their own (`selector: 'stamps-card'`), so that they never meet an app's `app-` ones.

## Placing a component

A view places a component by its tag, with the inputs as attributes in double quotes. From the bakery's `views/Shop/Catalog.php`:

```html
@if ($product->stock > 0)
    <app-add-to-cart product="{{ $product->id }}"></app-add-to-cart>
@else
    <p class="font-medium text-stone-500">{{ $this->t('Sold out for today') }}</p>
@endif
```

Live finds these tags in every HTML response after it was rendered, and replaces each with the component's HTML. The tag needs an empty body; attributes that are no input (`class`, `id`) stay on the tag. An attribute's value arrives as a string, which PHP converts for a typed property: `product="3"` fills an `int`. A `bool` or an array needs the view helper, which takes the values as they are:

```html
{{ $this->live('app-add-to-cart', ['product' => $product->id]) }}
```

It returns a `Waypoint\Html`, so the view's escaping `{{ }}` prints the component's markup as it is.

A page's inputs travel through the browser and back when the WebSocket connects, so they must be JSON values: numbers, strings, booleans, `null` and arrays of them. An object is refused with an error; pass its ID and let the component load it. A template that places a child component can pass any value, objects and closures included ([Child components](children.md)).

The rendered tag looks like this; the browser finds the component by `data-live` and creates it again from `data-inputs`:

```html
<app-add-to-cart data-live data-inputs="{&quot;product&quot;:&quot;3&quot;}"><div class="flex flex-wrap items-center gap-3">...</div></app-add-to-cart>
```

## Lifecycle

A component is created twice for a page view, and lives on in the second instance:

1. **Page render.** Live creates the component with `new`, sets its `#[Inject]` properties, then its inputs, renders the template into the page and drops the object.
2. **Connect.** `live.js` opens the WebSocket and sends each component's selector and inputs. The server creates the component again the same way, renders it, starts its [timers](push.md) and keeps it in the tab's *circuit*, the set of components and `#[Scoped]` services of one browser tab.
3. **Actions.** Every event calls a method; afterwards every top-level component of the tab renders again, and those whose HTML changed are sent.
4. **Gone.** When the tab closes, the circuit is kept for a while in case the browser comes back ([Reconnects](reconnect.md)). Then its timers stop and the components are dropped. A child component is dropped as soon as its parent's template no longer places it.

Things that follow from this:

- **The constructor runs before services and inputs are set.** It must not take required arguments, and it cannot use `#[Inject]` properties or inputs: they are not set yet. Use it for defaults that need code; compute what depends on services or inputs in a method the template calls, or in the action that needs it.

  ```php
  /** The time in the shop's header, for the customers who wait for the next tray. */
  #[Component]
  final class ShopClock
  {
      private string $time;

      public function __construct()
      {
          $this->tick();          // fine: needs nothing from outside
      }

      #[Every(1.0)]
      private function tick(): void
      {
          $this->time = date('H:i:s');
      }
  }
  ```

- **The constructor runs once per page view and once per connection.** Work with side effects (writing a record, sending a mail) belongs in an action, not in the constructor.
- **There is no mount or unmount hook.** What a component needs at the start it reads lazily, in the template or in its methods.
- **State lives in memory.** It does not survive a reload of the page, a restart of the worker or a deploy: a reload is a new circuit, which starts from the page's inputs again. State that must last goes into a service backed by a database.

## What a component may hold

Anything PHP can hold: objects, closures, open handles. Nothing is serialized; the object simply stays in the worker's memory. Keep it small all the same: every open tab holds its own components, and every action renders all of the tab's top-level components again.

See also: [`Component`](../reference/Waypoint/Live/Component.md), [`Input`](../reference/Waypoint/Live/Input.md), [`LivePlugin`](../reference/Waypoint/Live/LivePlugin.md), [`Components`](../reference/Waypoint/Live/Components.md).
