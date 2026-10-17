---
title: Testing
description: "Drive components in PHPUnit with Testing\\Tab and Testing\\Mounted: events through the template, timers on fake time, fakes for scoped services."
order: 140
---

[`Waypoint\Live\Testing\Tab`](../reference/Waypoint/Live/Testing/Tab.md) is a browser tab without a browser, for the app's PHPUnit tests. Its components get the app's services, and every event goes through the rendered template the way the browser sends it: `click('remove')` finds the element whose `data-on-click` names `remove` and sends what the browser would send. So a test also proves that the template wires the method up.

No WebSocket and no running server are involved. The tests run under plain PHP, or under Tenma when the app needs it: the bakery's `tests/Support/bootstrap.php` hands the PHPUnit run to `tenma`, so its services can wait on Postgres.

## A complete test

The bakery's tests share one test case, which builds the app from `app.php` on the test database with fresh demo data, and opens tabs for a customer and for staff. Shortened from `tests/Support/AppTestCase.php`:

```php
abstract class AppTestCase extends TestCase
{
    protected const OWNER = ['sub' => 1, 'name' => 'Anna', 'role' => 'owner'];

    protected const STAFF = ['sub' => 2, 'name' => 'Ben', 'role' => 'staff'];

    /** The cart cookie of the customer's browser in customerTab(). */
    protected const CART = '0b3c2a1e-5d4f-4a8b-9c7d-6e5f4a3b2c1d';

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__ . '/../../app.php';
        $this->app()->get(DatabaseTasks::class)->reset();
    }

    /** A tab of the customer's browser, with its cart cookie. */
    protected function customerTab(): Tab
    {
        return Tab::for($this->app(), Request::fromParts('GET', '/_live', cookies: ['cart' => self::CART]));
    }
}
```

`TestCase` is Waypoint's (`Waypoint\Testing\TestCase`): `$this->app()` is the app that `app.php` built, after `attach()`. A test of the add button, `tests/Components/AddToCartTest.php`:

```php
final class AddToCartTest extends AppTestCase
{
    public function testTheButtonStopsAtTheStock(): void
    {
        $this->db()->execute('UPDATE products SET stock = 1 WHERE id = 3');
        $this->customerTab()->mount('app-add-to-cart', ['product' => '3'])->click('add')->click('add')
            ->assertSee('1 in your cart')
            ->assertSee('That is all we have left.');
    }
}
```

`Tab::for()` needs the app after `attach()`, when the plugins are booted. The app's services are the app's: a test reaches them through `$this->app()->get()`, to prepare data or to check it afterwards.

## Mounting

```php
$tab = Tab::for($app);                               // a tab, as a new browser connection
$tab = Tab::for($app, $request);                     // with the connection's request: cookies, $request->jwt
$tracker = $tab->mount('app-order-tracker', ['code' => $order->code]);
```

[`mount()`](../reference/Waypoint/Live/Testing/Tab.md#mount) places a top-level component the way the browser does when it connects, with the inputs the page would pass, and returns a [`Mounted`](../reference/Waypoint/Live/Testing/Mounted.md). A tab may hold several components; they share the tab's `#[Scoped]` services, as in the browser.

[`Tab::page()`](../reference/Waypoint/Live/Testing/Tab.md#page) returns the HTML a page shows before any connection, which is what users without JavaScript see:

```php
$html = Tab::page($app, 'app-add-to-cart', ['product' => 3]);
$this->assertStringContainsString('Add to cart', $html);
```

## Events

Each event method takes a target: either a method name, which finds the element whose attribute names it (also as a bound call such as `remove($line->product)`), or a CSS selector, which finds the element it selects, provided that element has the attribute:

```php
$m->click('add');                                    // the element with data-on-click="add"
$m->click('li[data-key="K7Q2"] button');             // or a CSS selector: a bound call, picked by where it is
$m->input('input[type=search]', 'pretz');            // data-on-input, without the 150 ms wait
$m->change('input[aria-label="Lye pretzel"]', '55'); // data-on-change: a value, or true/false for a checkbox
$m->submit('form', ['name' => 'Mia', 'email' => 'mia@example.test', 'pickupDate' => $day]);   // data-on-submit with these fields
$m->upload('input[type=file]', 'pretzel.png', $bytes, 'image/png');                            // a file in a data-on-change file input
```

A target that matches nothing fails with `Nothing in <c0> has data-on-click="reset" or is "reset" with data-on-click`. That is the point: a method the template does not wire up cannot be called by a test either.

Each event method returns the `Mounted`, so assertions chain.

## Assertions and state

```php
$m->assertSee('2 in your cart');            // text, as the template prints it: escaped
$m->assertDontSee('Please confirm');
$m->html();                                 // the HTML the browser has inside the component's tag
$m->component();                            // the component object, for its state
$tab->assertNavigatedTo("/orders/$code/thanks");   // where the last navigate() sent the browser
```

`assertSee()` escapes its text first, as `{{ }}` would: `assertSee('Rye <b>')` finds `Rye &lt;b&gt;`. To look for markup, search `html()`, as `OrderTrackerTest` does for the current step:

```php
$this->assertMatchesRegularExpression('/aria-current="step"\s*>\s*In the oven/', $tracker->html());
```

## Children

[`find()`](../reference/Waypoint/Live/Testing/Mounted.md#find) reaches a child that the component's template placed, by selector and key, or the first one of its tag without a key. It returns a `Mounted` with the same methods:

```php
$desk = Tab::for($app)->mount('app-pickup-desk');
$row = $desk->find('app-pickup-row', 'K7Q2');
$row->click('handOver')->click('paid');
$desk->assertSee('Handed over: K7Q2');
```

## Timers

`#[Every]` timers run on fake time. [`advance()`](../reference/Waypoint/Live/Testing/Tab.md#advance) lets time pass and runs every timer that is due, in order:

```php
$tab = Tab::for($app);
$export = $tab->mount('app-sales-export');  // #[Every(0.5)] poll()
$tab->advance(1.0);                         // poll() runs twice
```

## Users and requests

The tab's `Connection` gets the request given to `Tab::for()`. The bakery's staff tab sets the JWT the back office's sign-in would leave:

```php
protected function staffTab(array $user): Tab
{
    $request = Request::fromParts('GET', '/_live');
    $request->jwt = $user;
    return Tab::for($this->app(), $request);
}

// StockEditorTest
$this->staffTab(self::STAFF)->mount('app-stock-editor')
    ->change('input[aria-label="Lye pretzel"]', '55')
    ->assertSee('Saved: Lye pretzel');
$this->assertSame(55, $this->sql('SELECT stock FROM products WHERE id = 3'));
```

## Fakes

[`with()`](../reference/Waypoint/Live/Testing/Tab.md#with) puts a service into the tab, in place of the `#[Scoped]` class it is or extends, before the first `mount()`:

```php
$tab = Tab::for($app)->with(new class extends Wishlist {
    public array $products = ['croissant', 'pretzel'];
});
$tab->mount('app-wishlist-badge')->assertSee('Saved (2)');
```

Anything else is refused: the app's services come from the app.

## Errors

An error that the browser would only see as `data-live-error` fails the test with its own exception and stack:

```php
$this->expectException(UnauthorizedException::class);
Tab::for($this->app())->mount('app-order-board');     // the board is for signed-in staff only
```

## Several tabs

Two tabs of one app share its app-wide services, so a [broadcast](push.md#changes-for-every-tab) in one renders the other. Under Tenma it travels through `Tenma::publish()` and takes a moment; the bakery's `settle()` waits for it ([Testing broadcasts](push.md#testing)):

```php
$morning = $this->staffTab(self::STAFF)->mount('app-order-board');
$office = $this->staffTab(self::OWNER)->mount('app-order-board');
$orders = $this->app()->get(Orders::class);
$order = $orders->place([4 => 3], 'Lena', 'lena@example.test', date('Y-m-d', strtotime('tomorrow')), 'en');
$orders->confirm($order->id);
$this->broadcast();
$morning->click('li[data-key="' . $order->code . '"] button');
$this->settle();
$this->assertMatchesRegularExpression('/data-key="baking".*data-key="' . $order->code . '"/s', $office->html());
```

## Against a running server

`Tab` drives the components in the test's own process. To test the app as it runs, with several workers, the bakery's `tests/SiteTest.php` starts `tenma --workers 2 index.php` and talks to `/_live` like a browser, with a small WebSocket client of its own (`tests/Support/LiveClient.php`, not part of Live). It sends the messages `live.js` sends:

```php
$board = new LiveClient($this->port, ['session' => JWT::encode(self::STAFF)]);
$board->mount('app-order-board');

$catalog = new LiveClient($this->port, $this->cookies);
$catalog->mount('app-add-to-cart', ['product' => '4']);
$catalog->call('add');

$checkout = new LiveClient($this->port, $this->cookies);
$checkout->mount('app-checkout');
$checkout->call('place', [['name' => 'Emma', 'email' => 'emma@example.test', 'pickupDate' => $day]]);

// The board, opened before the order existed, shows it without a reload, from whichever worker
$board->waitFor('/Emma.*2 × Butter croissant/s');
```

The messages are an implementation detail of Live and may change; `Tab` is the stable way to test components.

See also: [`Tab`](../reference/Waypoint/Live/Testing/Tab.md), [`Mounted`](../reference/Waypoint/Live/Testing/Mounted.md), [Static analysis](static-analysis.md).
