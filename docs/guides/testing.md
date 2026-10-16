---
title: Testing
description: Drive components in PHPUnit with Testing\Tab and Testing\Mounted - events through the template, timers on fake time, fakes for scoped services.
order: 140
---

[`Waypoint\Live\Testing\Tab`](../reference/Waypoint/Live/Testing/Tab.md) is a browser tab without a browser, for the app's PHPUnit tests. Its components get the app's services, and every event goes through the rendered template the way the browser sends it: `click('remove')` finds the element whose `data-on-click` names `remove` and sends what the browser would send. So a test also proves that the template wires the method up.

No WebSocket, no Tenma and no running server are involved; the tests run under plain PHP.

## A complete test

```php
<?php

namespace App\Tests;

use App\{TaskList, Tasks};
use PHPUnit\Framework\TestCase;
use Waypoint\App;
use Waypoint\Live\LivePlugin;
use Waypoint\Live\Testing\Tab;
use Waypoint\Waypoint;

final class TaskListTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        Waypoint::reset();
        $this->app = Waypoint::create();
        $this->app->plugin(new LivePlugin([TaskList::class]));
        $this->app->attach([]);
    }

    protected function tearDown(): void
    {
        Waypoint::reset();
    }

    public function testTasksCanBeFinishedAndDeleted(): void
    {
        $tasks = $this->app->get(Tasks::class);
        $tasks->add('Buy milk');
        $tasks->add('Call Anna');

        $list = Tab::for($this->app)->mount('app-task-list', ['title' => 'Home']);
        $list->assertSee('Home')->assertSee('Buy milk');

        $list->change('li[data-key="1"] input', true)->assertDontSee('Buy milk');
        $list->click('nav button:nth-child(2)')->assertSee('Buy milk')->assertDontSee('Call Anna');
        $list->click('show')->click('li[data-key="2"] button')->assertDontSee('Call Anna');
    }
}
```

`Tab::for()` needs the app after `attach()`, when the plugins are booted. The app's services are the app's: the test above reaches `Tasks` through `$this->app->get()` and fills it before mounting.

## Mounting

```php
$tab = Tab::for($app);                               // a tab, as a new browser connection
$tab = Tab::for($app, $request);                     // with the connection's request: cookies, $request->jwt
$counter = $tab->mount('app-counter', ['count' => 1]);
```

[`mount()`](../reference/Waypoint/Live/Testing/Tab.md#mount) places a top-level component the way the browser does when it connects, with the inputs the page would pass, and returns a [`Mounted`](../reference/Waypoint/Live/Testing/Mounted.md). A tab may hold several components; they share the tab's `#[Scoped]` services, as in the browser.

[`Tab::page()`](../reference/Waypoint/Live/Testing/Tab.md#page) returns the HTML a page shows before any connection, which is what users without JavaScript see:

```php
$html = Tab::page($app, 'app-counter', ['count' => 7]);
$this->assertStringContainsString('Clicked 7 times', $html);
```

## Events

Each event method takes a target: either a method name, which finds the element whose attribute names it (also as a bound call such as `remove($task)`), or a CSS selector, which finds the element it selects, provided that element has the attribute:

```php
$m->click('increment');                     // the element with data-on-click="increment"
$m->click('button.reset');                  // or a CSS selector; its data-arg goes along
$m->click('li[data-key="2"] button');       // a bound call, picked by where it is
$m->input('#search', 'Ber');                // data-on-input, without the 150 ms wait
$m->change('#agree', true);                 // data-on-change: a value, or true/false for a checkbox
$m->submit('form', ['title' => 'Trip']);    // data-on-submit with these fields
$m->upload('#photo', 'a.jpg', $bytes, 'image/jpeg');   // a file in a data-on-change file input
```

A target that matches nothing fails with `Nothing in <c0> has data-on-click="reset" or is "reset" with data-on-click`. That is the point: a method the template does not wire up cannot be called by a test either.

Each event method returns the `Mounted`, so assertions chain.

## Assertions and state

```php
$m->assertSee('Clicked 2 times');           // text, as the template prints it: escaped
$m->assertDontSee('admin');
$m->html();                                 // the HTML the browser has inside the component's tag
$m->component();                            // the component object, for its state
$tab->assertNavigatedTo('/login');          // where the last navigate() sent the browser
```

`assertSee()` escapes its text first, as `{{ }}` would: `assertSee('Hello <b>')` finds `Hello &lt;b&gt;`. To look for markup, search `html()`:

```php
$this->assertStringContainsString('value="50"', $export->html());
```

## Children

[`find()`](../reference/Waypoint/Live/Testing/Mounted.md#find) reaches a child that the component's template placed, by selector and key, or the first one of its tag without a key. It returns a `Mounted` with the same methods:

```php
$board = Tab::for($app)->mount('app-task-board');
$row = $board->find('app-task-row', 1);
$row->click('done');
$board->assertSee('Done: Buy milk');
```

## Timers

`#[Every]` timers run on fake time. [`advance()`](../reference/Waypoint/Live/Testing/Tab.md#advance) lets time pass and runs every timer that is due, in order:

```php
$tab = Tab::for($app);
$export = $tab->mount('app-export');        // #[Every(0.5)] poll()
$tab->advance(1.0);                         // poll() runs twice
```

## Users and requests

The tab's `Connection` gets the request given to `Tab::for()`:

```php
use Waypoint\Http\Request;

$request = Request::fromParts('GET', '/_live', cookies: ['session' => 'abc']);
$request->jwt = ['sub' => 7];
$list = Tab::for($app, $request)->mount('app-invoice-list');
```

## Fakes

[`with()`](../reference/Waypoint/Live/Testing/Tab.md#with) puts a service into the tab, in place of the `#[Scoped]` class it is or extends, before the first `mount()`:

```php
$tab = Tab::for($app)->with(new class extends Cart {
    public array $items = ['Tea' => 3];
});
$tab->mount('app-cart-badge')->assertSee('Cart (3)');
```

Anything else is refused: the app's services come from the app.

## Errors

An error that the browser would only see as `data-live-error` fails the test with its own exception and stack:

```php
$this->expectExceptionMessage('Not logged in');
Tab::for($app)->mount('app-account-menu')->click('openSettings');
```

## Several tabs

Two tabs of one app share its app-wide services, so a [broadcast](push.md#changes-for-every-tab) in one renders the other:

```php
$anna = Tab::for($app)->mount('app-chat');
$ben = Tab::for($app)->mount('app-chat');
$anna->submit('form', ['message' => 'Hello']);
$ben->assertSee('Hello');
```

See also: [`Tab`](../reference/Waypoint/Live/Testing/Tab.md), [`Mounted`](../reference/Waypoint/Live/Testing/Mounted.md), [Static analysis](static-analysis.md).
