---
title: Events and actions
description: Call a component's methods from the browser with data-on-click, -input, -change and -submit, pass values and bound arguments, and know what the browser cannot call.
order: 30
---

An attribute `data-on-<event>="method"` in a template wires a browser event to a method of the component. When the event fires, `live.js` sends the method's name (and at most one value) over the WebSocket; the server calls the method, renders the tab's components again and sends back what changed.

## The four events

| Attribute | Fires on | The method gets |
|---|---|---|
| `data-on-click` | a click on the element | nothing, or the element's `data-arg` as a string |
| `data-on-input` | typing in a field, sent 150 ms after the last keystroke | the field's value |
| `data-on-change` | a field's `change` event | the value; `true`/`false` for a checkbox; an `UploadedFile` (or a list with `multiple`) for a file input |
| `data-on-submit` | a form's submit | the form as an array ([Forms](forms.md)) |

`data-on-click` and `data-on-submit` call `preventDefault()`: a link with `data-on-click` does not follow its `href`, and a form does not post to the server the classic way. Other event names do nothing in the browser, and the checks warn about them.

A birthday cake to order, with a number field, a checkbox and a button:

```php
<?php
// src/Components/CakeOrder.php

namespace Bakery\Components;

use Waypoint\Live\Component;

/** A birthday cake to order: how many people, candles or not. */
#[Component]
final class CakeOrder
{
    public int $servings = 12;

    public bool $candles = false;

    public bool $express = false;

    public function serve(int $servings): void
    {
        $this->servings = max(4, $servings);
    }

    public function light(bool $candles): void
    {
        $this->candles = $candles;
    }

    public function save(bool $express = false): void
    {
        $this->express = $express;
    }
}
```

```html
<!-- src/Components/CakeOrder.Template.php -->
<input type="number" value="{{ $this->servings }}" data-on-input="serve">
<label><input type="checkbox" data-on-change="light" @if ($this->candles) checked @endif> Candles</label>
<button data-on-click="save">Order the cake</button>
<p>For {{ $this->servings }}, {{ $this->candles ? 'with candles' : 'without candles' }}</p>
```

Typing `7` sends `"7"`, which Live converts to `serve(7)` for the `int` parameter (below). Ticking the box calls `light(true)`.

### Typing and the order of events

`data-on-input` waits until the user stops typing for 150 ms. Any other event of the page first sends what is still waiting, so a quick click right after typing never overtakes the text. Events go out in the order they happened, also when one first has to read a file ([Uploads](uploads.md)).

## data-arg

A click passes the element's `data-arg` as a string. An order board that shows today's pickups or all of them:

```html
<button data-on-click="show" data-arg="today">Today</button>
<button data-on-click="show" data-arg="all">All days</button>
```

```php
public function show(string $days): void
{
    $this->days = $days === 'today' ? 'today' : 'all';
}
```

A click without `data-arg` brings no value, so a method with a required parameter gets a warning from the checks: give it a `data-arg`, or bind the argument (below).

`data-arg` is good for a fixed value written into the template. For anything that comes from the data (an ID, an object) a bound call is the better choice.

## Bound calls: remove($line->product)

A `data-on-*` attribute can call a method with arguments. The bakery's cart page, from `src/Components/CartEditor.Template.php`:

```html
@foreach ($this->cart->lines() as $line)
    <li data-key="{{ $line->product->id }}" class="flex items-center gap-4 py-3">
        <span class="flex-1 font-medium">{{ $line->product->name($this->i18n->locale()) }}</span>
        <button data-on-click="less($line->product)" aria-label="{{ $this->i18n->t('One less') }}">−</button>
        <span class="w-6 text-center tabular-nums">{{ $line->quantity }}</span>
        <button data-on-click="more($line->product)" aria-label="{{ $this->i18n->t('One more') }}">+</button>
        <button data-on-click="remove($line->product)" class="text-sm text-stone-500 underline">{{ $this->i18n->t('Remove') }}</button>
    </li>
@endforeach
```

```php
/** The lines of the cart page. The buttons are bound to the product on the server: the browser only clicks. */
#[Component]
final class CartEditor
{
    #[Inject]
    private Cart $cart;

    #[Inject]
    private Translator $i18n;

    public function more(Product $product): void
    {
        $this->cart->add($product);
    }

    public function less(Product $product): void
    {
        $this->cart->set($product, $this->cart->quantity($product->id) - 1);
    }

    public function remove(Product $product): void
    {
        $this->cart->set($product, 0);
    }
}
```

The arguments are evaluated on the server while the template renders, and they stay there: values of any type, objects and closures included. The browser only gets an ID for the call:

```html
<button data-on-click="remove~e535d152bec9c4f8" class="text-sm text-stone-500 underline">Remove</button>
```

A click sends that ID back, and the server calls `remove()` with the very `Product` the template had, price and stock included. Nothing about the product travels through the browser, so nothing about it can be forged.

- **What the event brings comes after the bound arguments.** The bakery's stock page, `StockEditor`, has one field per product with `data-on-change="set($product)"`; a change calls `set($product, $value)`:

  ```html
  <input type="number" min="0" value="{{ $product->stock }}" data-on-change="set($product)" aria-label="{{ $product->name($this->i18n->locale()) }}">
  ```

  ```php
  /** An emptied field changes nothing. */
  public function set(Product $product, ?int $stock): void
  {
      if ($stock !== null) {
          $this->products->setStock($product->id, $stock);
          $this->saved = $this->i18n->t('Saved: {product}', ['product' => $product->name($this->i18n->locale())]);
      }
  }
  ```

  An emptied field sends `""`, which fills the nullable `?int` as `null` (below). A method that takes no further parameter ignores the value.
- **Strings inside the attribute use single quotes**, since a `"` ends the attribute: `data-on-click="move($order, 'ready')"`.
- **The same call gets the same ID in every render.** Values count by value. An enum case, and an object that cannot change (a `readonly` class, or one whose properties are all `readonly`), count by class and properties, so a row that every render builds again from the database keeps its ID: the bakery's `Product` and `Order` are `readonly` classes loaded from Postgres on every render. Any other object counts as that very object: a list that is reordered keeps its IDs, a removed item takes its ID along, and an object built again by the next render gets a new ID.
- **A call stays valid for two renders.** The server keeps the bound calls of the last two renders of each component, so a click that was on its way during a render still arrives. A click on an item that is gone since then does nothing: the order board's `advance($order)` on an order another member of staff has moved on already reaches a method that finds it moved, and nothing happens.

## After an action

Every action, successful or not, is followed by a render of every top-level component of the tab, children included. The server sends the components whose HTML changed; the browser patches the new HTML into the page:

- Siblings with a `data-key` (and child components, which get one automatically) are matched by key and moved, so a reordered list keeps its elements, and the focus and caret inside them.
- Other siblings are matched in order among those with the same tag, so a message that shows up in front of an input leaves the input alone.
- Form fields keep what the user typed until the server renders a different value for them ([Forms and binding](forms.md#fields-and-the-server)).
- An element with `data-live-ignore` belongs to the browser once it is on the page: a render does not touch it or anything inside. Use it for an editor, a map or another widget that keeps its own DOM, with a `data-key` so it moves along with its row.

```html
@foreach ($this->products->all() as $product)
    <div data-key="{{ $product->id }}">
        <h3>{{ $product->name('en') }}</h3>
        <div class="editor" data-live-ignore></div>
    </div>
@endforeach
```

## When an action fails

An exception in an action is logged with its stack. The browser only learns that the component failed: its tag gets the attribute `data-live-error`, which a stylesheet can show. The circuit keeps running, the other components of the tab are not affected, and the next successful render removes the attribute. What the method changed before it threw is rendered all the same.

```css
[data-live-error] { outline: 2px solid #e5484d; }
```

In development the error also shows in the page and in the editor ([Development tools](development.md#errors-in-the-page)).

## What the browser can call

The browser names a method; the server decides whether it may:

- **Only methods the template names.** A public method of the class that no `data-on-*` attribute names stays out of reach, even though it is public. A method named only in commented-out markup (`<!-- -->` or `{{/* */}}`) is not named.
- **Only public, non-static methods.** The editor and the checks flag a `data-on-click` that names a private, static or missing method.
- **Bound calls only by their ID.** `remove($line->product)` is reachable only through the IDs of the last two renders, with the arguments the template bound.

A parent's method that a child calls through a closure input is not named in any template, so the browser cannot call it directly ([Child components](children.md#events-up-to-the-parent)).

## One value from the browser

A call takes at most one value from the browser: the one its event brings (the field's value, the form, `data-arg`). It fills the first parameter the template does not bind; any further parameter keeps its default. A message with more values is refused.

That one value is input like a query parameter. A forged message can send it even for a click that has no `data-arg`, so `save(bool $express = false)` of the cake order above can be called as `save("1")` by anyone who writes their own WebSocket messages. Design methods so that this value is harmless or checked:

```php
// The browser can choose $express: check that the bakery bakes express cakes today
public function save(bool $express = false): void
{
    if ($express && !$this->calendar->expressToday()) {
        throw new ForbiddenException('No express cakes today');
    }
    // ...
}
```

The value is converted to the type of the parameter it fills, by the rules `Waypoint\FromArray` follows for a `#[Body]` DTO. A value that does not fit is refused: the method is not called, the component shows no error, and in development the log says why, as for a click on a call that is gone. Nothing is cast:

| Browser sends | Parameter | Method gets |
|---|---|---|
| `"7"` | `int $servings` | `7` |
| `"7.5"`, `"abc"`, `""` | `int $servings` | refused |
| `""` | `?int $stock` | `null` |
| `"2.5"` | `float $kilos` | `2.5` |
| `"false"`, `"off"`, `"0"` | `bool $express` | `false` |
| `true` (checkbox) | `bool $candles` | `true` |
| `"baking"` | `OrderStatus $status` (a backed enum) | `OrderStatus::from('baking')`, or refused for a value that is no case |
| the form's array | `CheckoutForm $form` (a `FromArray` DTO) | a `CheckoutForm` built from it ([Forms](forms.md#a-dto-for-the-form)) |
| a string | `array $form` | refused |

A parameter without a type, or `mixed`, gets the value as it came. Check values in the method as you would any other request input.

See also: [Forms and binding](forms.md), [Security model](security.md), [Testing](testing.md).
