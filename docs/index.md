---
title: Waypoint Live
description: "Live components for Waypoint under Tenma: PHP classes with state on the server, events and renders over one WebSocket per tab."
order: 1
---

Waypoint Live adds live components to a [Waypoint](https://github.com/systonia/Waypoint) app that runs under [Tenma](https://tenma.dev). A live component is a PHP class with state and methods, plus a template. The page renders it like any view; after that, the browser opens one WebSocket per tab, a click or a keystroke calls the component's method on the server, and the new HTML comes back and is patched into the page.

The component stays in the server's memory for as long as the tab is open. It calls the app's services directly: there is no API route, no JSON endpoint and no `fetch()` between the page and the code that answers it. The browser runs one small script that comes with the package; the app writes no JavaScript of its own.

```php
#[Component]
final class AddToCart
{
    #[Input]
    public int $product = 0;

    private int $quantity = 0;

    public function add(): void
    {
        $this->quantity++;
    }
}
```

```html
<!-- AddToCart.Template.php -->
<button data-on-click="add">Add to cart</button>
<span>{{ $this->quantity }} in your cart</span>
```

The examples in these docs come from [Sonnenkorn](https://github.com/systonia/Waypoint-Bakery), a made-up bakery whose shop, cart, checkout and order board are live components. Where a guide quotes its code, it names the file.

**Status:** proof of concept. The package runs under Tenma only and keeps every open tab's components in the memory of one worker; see [Deployment and limits](guides/deployment.md).

## Install

Live needs PHP 8.5, Tenma and `systonia/waypoint` 0.7 or later:

```bash
composer require systonia/waypoint-live
```

Register the plugin with the components the app has. Only the classes listed here can be created; the browser names them by their tag and never by class. From the bakery's `app.php`:

```php
$app->plugin(new LivePlugin([
    CartBadge::class, AddToCart::class, CartEditor::class, Checkout::class, OrderTracker::class,
    OrderBoard::class, StockEditor::class,
]));
```

The layout loads Waypoint's script and the plugin's asset tags, which bring `live.js`. Both return markup, which `{{ }}` prints as it is:

```html
{{ $this->waypointJsTag() }}
{{ $this->pluginAssetTags() }}
```

For [static analysis](guides/static-analysis.md), add `phpstan/phpstan` as a dev dependency; with `phpstan/extension-installer` the package's PHPStan extension is included on its own.

## A first component

A shop with one page and an **Add to cart** button on it. The project has the bakery's layout:

```
composer.json                          "autoload": {"psr-4": {"Bakery\\": "src/"}}
index.php
src/Components/AddToCart.php
src/Components/AddToCart.Template.php
src/Controllers/ShopController.php
views/_Layout.php
views/Shop/Catalog.php
```

The component is a class with the `#[Component]` attribute. `#[Input]` marks a property the page may set; everything else is the component's own state:

```php
<?php
// src/Components/AddToCart.php

namespace Bakery\Components;

use Waypoint\Live\{Component, Input};

#[Component]
final class AddToCart
{
    #[Input]
    public int $product = 0;

    private int $quantity = 0;

    public function add(): void
    {
        $this->quantity++;
    }

    public function remove(): void
    {
        $this->quantity = max(0, $this->quantity - 1);
    }
}
```

The template sits next to the class and is named after it. `$this` is the component, `{{ }}` prints a value HTML-escaped, and `data-on-click` names the method a click calls:

```html
<!-- src/Components/AddToCart.Template.php -->
<button data-on-click="add">Add to cart</button>
@if ($this->quantity > 0)
    <span>{{ $this->quantity }} in your cart</span>
    <button data-on-click="remove">One less</button>
@endif
```

A view places the component by its tag. The tag is `app-` and the class name in kebab case, and its attributes set the inputs:

```html
<!-- views/Shop/Catalog.php -->
@section('title')
    Shop
@endsection

<h1>
    Baked this morning
</h1>
<p>Lye pretzel, 0.90 €</p>
<app-add-to-cart product="3"></app-add-to-cart>
```

```html
<!-- views/_Layout.php -->
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title') · Sonnenkorn</title>
</head>
<body>
{{ $content }}
{{ $this->waypointJsTag() }}
{{ $this->pluginAssetTags() }}
</body>
</html>
```

```php
<?php
// src/Controllers/ShopController.php

namespace Bakery\Controllers;

use Waypoint\Attributes\{Controller, Get};
use Waypoint\Http\View;

#[Controller]
final class ShopController
{
    #[Get('/')]
    public function catalog(): View
    {
        return new View('Shop/Catalog');
    }
}
```

```php
<?php
// index.php

require __DIR__ . '/vendor/autoload.php';

use Bakery\Components\AddToCart;
use Bakery\Controllers\ShopController;
use Waypoint\Live\LivePlugin;
use Waypoint\Options\RendererOptions;
use Waypoint\Waypoint;

$app = Waypoint::create();
$app->plugin(new LivePlugin([AddToCart::class]));
$app->configure(function (RendererOptions $views) {
    $views->directory = __DIR__ . '/views';
    $views->layout = '_Layout.php';
});
$app->attach([ShopController::class]);

$app->serve();
```

Run it and open `http://localhost:8080`:

```bash
tenma index.php
```

What happens:

1. The page's response contains `<app-add-to-cart product="3"></app-add-to-cart>`. Live creates an `AddToCart`, sets `$product` to 3, renders the template and puts the result into the tag: `<app-add-to-cart data-live data-inputs="{&quot;product&quot;:&quot;3&quot;}"><button data-on-click="add">Add to cart</button></app-add-to-cart>`. Without JavaScript the page stays like that.
2. `live.js` opens a WebSocket to `/_live`. The server creates the component again from the same inputs and keeps it in memory for this tab.
3. A click on **Add to cart** sends `add` over the WebSocket. The server calls `$addToCart->add()`, renders the template again and sends the HTML back, and the browser patches it into the page.

The same component in a PHPUnit test, without a browser (see [Testing](guides/testing.md)):

```php
$tab = Tab::for($app);
$button = $tab->mount('app-add-to-cart', ['product' => 3]);
$button->click('add')->click('add')->assertSee('2 in your cart');
```

The bakery's own [`AddToCart`](https://github.com/systonia/Waypoint-Bakery/blob/main/src/Components/AddToCart.php) keeps the quantities in a cart that every component of the tab shares and Postgres keeps, and stops at the stock: [Services](guides/services.md) shows it.

During development, start with `APP_ENV=development tenma --watch index.php`: a saved template shows at once in the open tab, and errors show in the page and in the editor ([Development tools](guides/development.md)).

## How these docs are organised

- **[Guides](guides/index.md)** explain one part of Live each, with examples from the bakery: components, templates, events, child components, forms, services, the connection, navigation, uploads, server push, reconnects, development tools, static analysis, testing, security and deployment.
- **[Reference](reference/index.md)** has one page per public class, generated from the code with `tenma docs` (`composer reference` runs it): every attribute, method and option with its signature.

Start with [Components](guides/components.md), [Templates](guides/templates.md) and [Events and actions](guides/events.md); they cover what every component uses. Read [Security model](guides/security.md) before a component shows data that is not for everyone.
