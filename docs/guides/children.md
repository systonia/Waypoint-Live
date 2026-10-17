---
title: Child components and inputs
description: Place components inside a template, pass them values and closures, keep their state across renders with keys, and send events up to the parent.
order: 40
---

A template places other components by their tag, the way a view does. Each child is a component of its own, with its own state and actions, and it lives as long as the parent's template keeps placing it.

## A desk with rows

The bakery's order board moves every order through the oven. At the counter, a smaller board could hand the ready orders over: one row per order, and each row asks whether the customer has paid before it tells the desk. The desk uses the bakery's `Orders` repository:

```php
<?php
// src/Components/PickupDesk.php

namespace Bakery\Components;

use Bakery\Models\{Order, OrderStatus};
use Bakery\Repositories\Orders;
use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

/** The shop counter: the orders that are ready, one row each. */
#[Component]
final class PickupDesk
{
    #[Inject]
    private Orders $orders;

    private ?string $message = null;

    /** Called by a PickupRow through its $onHandedOver input; no data-on-* names it, so the browser cannot. */
    public function handedOver(Order $order): void
    {
        if ($this->orders->advance($order->id, OrderStatus::Ready)) {
            $this->message = "Handed over: $order->code";
        }
    }

    /** @return list<Order> */
    private function ready(): array
    {
        return array_values(array_filter($this->orders->open(), fn (Order $order) => $order->status === OrderStatus::Ready));
    }
}
```

```html
<!-- src/Components/PickupDesk.Template.php -->
@if ($this->message !== null)
    <p role="status">{{ $this->message }}</p>
@endif
<ul>
    @foreach ($this->ready() as $order)
        <app-pickup-row key="{{ $order->code }}" order="{{ $order }}" onHandedOver="{{ $this->handedOver(...) }}" compact></app-pickup-row>
    @endforeach
</ul>
```

```php
<?php
// src/Components/PickupRow.php

namespace Bakery\Components;

use Bakery\Models\Order;
use Closure;
use Waypoint\Live\{Component, Input};

/** One order at the counter: asks whether it was paid before it goes. */
#[Component]
final class PickupRow
{
    #[Input]
    public Order $order;

    /** @var (Closure(Order): void)|null */
    #[Input]
    public ?Closure $onHandedOver = null;

    #[Input]
    public bool $compact = false;

    private bool $asking = false;

    public function handOver(): void
    {
        $this->asking = true;
    }

    public function back(): void
    {
        $this->asking = false;
    }

    public function paid(): void
    {
        ($this->onHandedOver)($this->order);
    }
}
```

```html
<!-- src/Components/PickupRow.Template.php -->
<li>
    {{ $this->order->code }}: {{ $this->order->customerName }}
    @if (!$this->compact)
        <span>{{ count($this->order->lines) }} items</span>
    @endif
    @if ($this->asking)
        <button data-on-click="paid">Paid, hand over</button>
        <button data-on-click="back">Not yet</button>
    @else
        <button data-on-click="handOver">Hand over</button>
    @endif
</li>
```

Both classes are listed in `LivePlugin`; a child is created like any component, so only listed ones can be placed.

The desk renders each row inside its tag, with an ID the server gives it:

```html
<ul><app-pickup-row data-live data-live-id="c0.0"><li>
    K7Q2: Mia<button data-on-click="handOver">Hand over</button></li>
</app-pickup-row><app-pickup-row data-live data-live-id="c0.1"><li>
    P4ZD: Lena<button data-on-click="handOver">Hand over</button></li>
</app-pickup-row></ul>
```

A click inside a row goes to the row, by that ID: the browser sends events to the innermost component around the element.

## Passing inputs

Attributes set the child's `#[Input]` properties:

| Attribute | The child gets |
|---|---|
| `order="{{ $order }}"` | the value itself: here the `Order` object |
| `onHandedOver="{{ $this->handedOver(...) }}"` | the closure |
| `title="Order {{ $order->code }}"` | a string: text and values mixed |
| `title="Counter"` | the string `Counter` |
| `compact` (no value) | `true` |
| `key="{{ $order->code }}"` | no input: the child's identity (below) |

A value that is a single `{{ expr }}` (or `<?= expr ?>`) passes the value itself, of any type; anything else passes a string. Attribute names are the property names as written (`onHandedOver`, not `on-handed-over`). An attribute that is no input of the child is an error when the template renders, and the editor and the checks report it with the inputs the child has.

The tag needs double quotes around values and an empty body: `<app-pickup-row ...></app-pickup-row>` or `<app-pickup-row ... />`. A tag in another form stays in the HTML as it is, and the checks report it. There is no content projection: what a child shows comes from its own template.

## Identity and state

A child keeps its object, and with it its state, as long as the parent's template places it again:

- **With `key`** the child is identified by its selector and key. A row whose order moves to another position keeps its state (`$asking` above), and so do its siblings when a row above them goes.
- **Without `key`** the child is identified by its position among the tags of that selector in the parent's template. That is fine for a fixed set of children; for a list it mixes up state as soon as an item is inserted or removed.
- **Two children with the same key** in one render are an error. A key must be a string or a number.
- **A child that is no longer placed is removed**: its timers stop, and placing it again later creates a new one.

Use a key for every child placed in a loop.

### Inputs on later renders

On each render of the parent, only inputs whose value changed are set again. A child that changed one of its own inputs keeps that change until the parent passes a different value. Values compare with `===`: the same object or an equal string counts as unchanged, while a new closure from `$this->handedOver(...)` is a new value each time and is set again, which does no harm.

## Events up to the parent

A child tells its parent about something through a closure input. The parent passes one of its methods as a first-class callable, and the child calls it:

```html
<app-pickup-row ... onHandedOver="{{ $this->handedOver(...) }}"></app-pickup-row>
```

```php
public function paid(): void
{
    ($this->onHandedOver)($this->order);
}
```

A click on the row's **Paid, hand over** calls `PickupRow::paid()`, which runs `PickupDesk::handedOver($order)`. No template names `handedOver`, so the browser cannot call it directly: only the row can, with its own order. A closure is a good way to give a child exactly one capability of the parent.

After the action the whole tab renders again, parent included, so the desk shows its message and the order, now picked up, disappears from the list.

## Renders

Every change renders every top-level component of the tab again, with all its children. A child has no render of its own: its HTML travels inside its top-level component's. This keeps state shared between components consistent without bookkeeping, and is fine for pages of moderate size; see [Deployment and limits](deployment.md#limits).

## Testing children

[`Mounted::find()`](../reference/Waypoint/Live/Testing/Mounted.md#find) reaches a child by selector and key, with the same methods as the top-level component:

```php
// Two orders are ready: K7Q2 for Mia, P4ZD for Lena
$desk = Tab::for($app)->mount('app-pickup-desk');
$row = $desk->find('app-pickup-row', 'K7Q2');
$row->click('handOver')->assertSee('Paid, hand over');
$desk->find('app-pickup-row', 'P4ZD')->assertDontSee('Paid, hand over');   // each row its own state
$row->click('paid');
$desk->assertSee('Handed over: K7Q2')->assertDontSee('Mia');
```

See also: [Components](components.md), [Events and actions](events.md), [Testing](testing.md).
