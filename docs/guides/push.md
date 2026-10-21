---
title: Timers, broadcasts and server push
description: "Update a component from the server with #[Every] timers, render other tabs with Broadcast, and reach every worker with Messaging\Channel::publish()."
order: 100
---

A tab renders after its own actions. Two things render it without an event from its browser: a timer of one of its components, and a broadcast from somewhere else in the app.

## Timers with #[Every]

[`#[Every(seconds)]`](../reference/Waypoint/Live/Every.md) on a method runs it on a timer while the browser is connected. After each run the tab renders, and what changed is pushed to the browser:

```php
<?php
// src/Components/ShopClock.php

namespace Bakery\Components;

use Waypoint\Live\{Component, Every};

/** The time in the shop's header, for the customers who wait for the next tray. */
#[Component]
final class ShopClock
{
    private string $time;

    public function __construct()
    {
        $this->tick();
    }

    #[Every(1.0)]
    private function tick(): void
    {
        $this->time = date('H:i:s');
    }
}
```

```html
<!-- src/Components/ShopClock.Template.php -->
<time>{{ $this->time }}</time>
```

A timer method may be private, and the browser cannot call it unless the template also names it in a `data-on-*` attribute. A render that changes nothing sends nothing, so a timer that finds nothing new costs a render on the server and no traffic.

A timer that watches a job, here an export of the year's sales in the back office:

```php
<?php
// src/Components/SalesExport.php

namespace Bakery\Components;

use Waypoint\Live\{Component, Every};

/** The sales of the year as a file: shows how far the export got, then the link. */
#[Component]
final class SalesExport
{
    private int $progress = 0;

    #[Every(0.5)]
    public function poll(): void
    {
        if ($this->progress < 100) {
            $this->progress += 25;    // in the app: ask the job how far it got
        }
    }
}
```

```html
<!-- src/Components/SalesExport.Template.php -->
@if ($this->progress < 100)
    <progress max="100" value="{{ $this->progress }}"></progress>
@else
    <a href="/backoffice/orders.xlsx">Download</a>
@endif
```

When timers run:

- **Only while connected.** Timers start when the component is created in the tab's circuit; the page's first render starts none. The first run comes one interval after that.
- **Paused while disconnected.** When the WebSocket drops, the tab's timers pause; after a [reconnect](reconnect.md) they start again.
- **Stopped for good** when the component is removed (a child no longer placed) or the tab is gone.
- **Like an action.** An exception in a timer is logged and marks the component with `data-live-error`; the timer keeps running. A timer may call [`navigate()`](navigation.md).

A timer method takes no required parameters, is not static, and its interval is more than 0. The development checks and `waypoint-check` report a timer that breaks one of these rules. In development, the console and the log warn when timers send more than 10 renders a second in one tab.

A component with many timers in many tabs adds up: 1,000 open tabs with a one-second timer are 1,000 renders a second. Prefer a longer interval, or a broadcast when the change happens: the bakery's order board has no timer at all, it renders when an order changes.

## Changes for every tab

A tab renders after its own actions and timers. When state that tabs share changes, the other tabs need to hear about it: [`Broadcast::changed()`](../reference/Waypoint/Live/Broadcast.md#changed) renders every connected tab, and each sends the components whose HTML changed.

The bakery's orders live in Postgres. Whoever changes one calls `changed()`, and every open order board and every customer's order page reads the orders again. A step on the board, from `src/Components/OrderBoard.php`:

```php
/**
 * Every open order in columns by status, for everyone signed in to the back office. A new order from the shop, or
 * a step another member of staff took in another tab or on another worker, shows up here on its own: whoever
 * changes an order calls Broadcast::changed(), which renders every tab of every worker.
 */
#[Component]
#[Authenticated]
final class OrderBoard
{
    #[Inject]
    private Orders $orders;

    #[Inject]
    private Broadcast $broadcast;

    /** Bound to the order the board showed: the click moves it from the status it had then, or does nothing. */
    public function advance(Order $order): void
    {
        if ($this->orders->advance($order->id, $order->status)) {
            $this->broadcast->changed();
        }
    }
}
```

The checkout calls it after a new order ([Forms](forms.md#the-checkout)), the customer's `OrderTracker` after a confirmation, and the board after every step: the customer's page follows the bakery, and the board shows new orders at once.

Outside a component, for example in a controller or a timer of the same process, take the broadcast from the app: `$app->get(Broadcast::class)->changed()`. The bakery's `index.php` cancels unconfirmed orders on a timer and tells every tab:

```php
$orders = $app->get(Orders::class);
$broadcast = $app->get(Broadcast::class);
$every('EXPIRY_INTERVAL', 30, function () use ($orders, $broadcast, $env) {
    if ($orders->expireUnconfirmed($env->int('UNCONFIRMED_MINUTES', 15)) > 0) {
        $broadcast->changed();
    }
});
```

A tab whose connection dropped renders when it comes back.

A broadcast renders every tab of every user, whether it shows an order or not; tabs whose HTML did not change send nothing. It is meant for changes that are rare compared to the number of open tabs.

Each tab renders with its own connection's request values (its locale, its request id in the log), not with those of the request that called `changed()` ([The connection](connection.md#connection)): a customer's order page stays in German when an English-speaking member of staff moves the order on.

## Several workers

Each Tenma worker has its own memory, its own tabs and its own instance of every app-wide service. Under a Tenma with `Messaging\Channel::publish()`, `changed()` goes out through it: every worker renders its tabs, and with `tenma --pubsub redis://host` every process on that Redis too. A worker subscribes to these broadcasts when its first tab connects, so a script that boots the app without a server (a CLI task, a test) ends as usual.

That is enough when the shared state lives outside the workers, in Postgres or Redis: each worker renders from the same source. The bakery runs with `tenma --workers 2 index.php`, and its `tests/SiteTest.php` plays it through: a click on the board moves the customer's page on, whichever worker serves each tab.

State in memory differs per worker. Say the staff announce what just came out of the oven, kept in an app-wide `Specials` service rather than in Postgres:

```php
<?php
// src/Services/Specials.php

namespace Bakery\Services;

/** One for the whole app, in this worker's memory: what the oven just brought. */
final class Specials
{
    /** @var list<string> */
    public array $news = [];
}
```

```php
<?php
// src/Components/OvenNews.php

namespace Bakery\Components;

use Bakery\Services\Specials;
use Waypoint\Attributes\Inject;
use Waypoint\Live\{Broadcast, Component};

/** "Fresh from the oven": a line the staff writes, which every open shop page shows at once. */
#[Component]
final class OvenNews
{
    #[Inject]
    private Specials $specials;

    #[Inject]
    private Broadcast $broadcast;

    /** @param array{news?: string} $form */
    public function announce(array $form): void
    {
        $news = trim($form['news'] ?? '');
        if ($news === '') {
            return;
        }
        $this->specials->news[] = mb_substr($news, 0, 200);
        $this->broadcast->changed();
    }
}
```

```html
<!-- src/Components/OvenNews.Template.php -->
<ul>
    @foreach (array_slice($this->specials->news, -3) as $news)
        <li>{{ $news }}</li>
    @endforeach
</ul>
<form data-on-submit="announce">
    <input name="news" autocomplete="off">
    <button>Announce</button>
</form>
```

With one worker that is all. With several, send the change itself to every worker, and let each update its own copy and render its own tabs with [`changedHere()`](../reference/Waypoint/Live/Broadcast.md#changedhere):

```php
// In index.php, before $app->serve(): runs in every worker
$specials = $app->get(Specials::class);
$broadcast = $app->get(Broadcast::class);
new Messaging\Channel('oven')->subscribe(function (string $news) use ($specials, $broadcast) {
    $specials->news[] = $news;
    $broadcast->changedHere();
});
```

```php
// In the action, instead of changing Specials and calling changed()
public function announce(array $form): void
{
    $news = trim($form['news'] ?? '');
    if ($news !== '') {
        new Messaging\Channel('oven')->publish(mb_substr($news, 0, 200));
    }
}
```

`changedHere()` renders only the tabs of the worker it runs in. Calling `changed()` in a subscription instead would render every tab once per worker.

Under a Tenma without `Messaging\Channel::publish()`, and in tests under plain PHP, `changed()` renders the tabs of its own worker at once. Tests that run under Tenma, as the bakery's do, see the broadcast go through `Messaging\Channel::publish()`, so it arrives a moment later (below).

## Testing

Timers run on fake time in [`Testing\Tab`](testing.md):

```php
$tab = Tab::for($app);
$export = $tab->mount('app-sales-export');
$tab->advance(1.0);                                  // two runs of poll()
$this->assertStringContainsString('value="50"', $export->html());
```

Two tabs of one app share its services, so a broadcast in one renders the other:

```php
$counter = Tab::for($app)->mount('app-oven-news');
$shop = Tab::for($app)->mount('app-oven-news');
$counter->submit('form', ['news' => 'Pretzels, still warm']);
$shop->assertSee('Pretzels, still warm');
```

The bakery runs its PHPUnit tests under Tenma, for Postgres, so its broadcasts travel through `Messaging\Channel::publish()`. Its test case waits a moment for them (`tests/Support/AppTestCase.php`):

```php
/** What the app does after an order changed. The broadcast travels through Messaging\Channel::publish(), so it takes a moment. */
protected function broadcast(): void
{
    $this->app()->get(Broadcast::class)->changed();
    $this->settle();
}

/** Lets broadcasts that are on their way reach the tabs. */
protected function settle(): void
{
    Threading\Task::delay(0.2);
}
```

and `tests/Components/OrderBoardTest.php` opens two boards, places an order and sees it on both:

```php
$morning = $this->staffTab(self::STAFF)->mount('app-order-board');
$office = $this->staffTab(self::OWNER)->mount('app-order-board');
$morning->assertDontSee('Lena');

$orders = $this->app()->get(Orders::class);
$order = $orders->place([4 => 3], 'Lena', 'lena@example.test', date('Y-m-d', strtotime('tomorrow')), 'en');
$this->broadcast();   // what the checkout does
$morning->assertSee('Lena')->assertSee('3 × Butter croissant')->assertSee('Not confirmed yet');
$office->assertSee('Lena');
```

See also: [`Every`](../reference/Waypoint/Live/Every.md), [`Broadcast`](../reference/Waypoint/Live/Broadcast.md), [Deployment and limits](deployment.md).
