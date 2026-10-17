---
title: "Tab"
description: "One browser tab in a test, without a browser: the app's components with its services, driven through the same messages the browser sends, so a test proves the template's wiring too."
order: 100
generated: true
---

`Waypoint\Live\Testing\Tab` · final class · namespace [`Waypoint\Live\Testing`](index.md)

```php
final class Tab
```

One browser tab in a test, without a browser: the app's components with its services, driven through the same
messages the browser sends, so a test proves the template's wiring too.

    $tab = Tab::for($app);
    $button = $tab->mount('app-add-to-cart', ['product' => 3]);
    $button->click('add')->assertSee('1 in your cart');
    $tab->advance(1.0);                              // #[Every] timers

An error the browser would only see as `data-live-error` fails the test with its own stack. Two tabs of one app
share its services, so a Broadcast reaches the other.

## Methods

### for()

```php
public static function for(\Waypoint\App $app, ?\Waypoint\Http\Request $request = null): \Waypoint\Live\Testing\Tab
```

A tab of $app, once attach() has booted its plugins; $request is the connection's (cookies, the user).

**Parameters**

- `$app` `\Waypoint\App`
- `$request` `?\Waypoint\Http\Request`

**Returns** [`Tab`](Tab.md)

### page()

```php
public static function page(
    \Waypoint\App $app,
    string $selector,
    array $inputs = [],
    ?\Waypoint\Http\Request $request = null,
): string
```

The HTML a page shows before any connection: for what users without JavaScript see.

**Parameters**

- `$app` `\Waypoint\App`
- `$selector` `string`
- `$inputs` `array<string, mixed>`
- `$request` `?\Waypoint\Http\Request`

**Returns** `string`

### with()

```php
public function with(object $service): \Waypoint\Live\Testing\Tab
```

Puts a service, such as a fake, into this tab before its components are created, for the #[Scoped] class it is or extends.

**Parameters**

- `$service` `object`

**Returns** [`Tab`](Tab.md)

### mount()

```php
public function mount(string $selector, array $inputs = []): \Waypoint\Live\Testing\Mounted
```

Places a top-level component as the browser does when it connects, and renders it.

**Parameters**

- `$selector` `string`
- `$inputs` `array<string, mixed>`: As the page passes them

**Returns** [`Mounted`](Mounted.md)

### advance()

```php
public function advance(float $seconds): \Waypoint\Live\Testing\Tab
```

Lets $seconds pass: the #[Every] timers that are due run, in order.

**Parameters**

- `$seconds` `float`

**Returns** [`Tab`](Tab.md)

### assertNavigatedTo()

```php
public function assertNavigatedTo(string $url): \Waypoint\Live\Testing\Tab
```

That the last navigate() of an action or timer in this tab asked for $url.

**Parameters**

- `$url` `string`

**Returns** [`Tab`](Tab.md)
