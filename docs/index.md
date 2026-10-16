---
title: Waypoint Live
description: Live components for Waypoint under Tenma - PHP classes with state on the server, events and renders over one WebSocket per tab.
order: 1
---

Waypoint Live adds live components to a [Waypoint](https://github.com/systonia/Waypoint) app that runs under [Tenma](https://tenma.dev). A live component is a PHP class with state and methods, plus a template. The page renders it like any view; after that, the browser opens one WebSocket per tab, a click or a keystroke calls the component's method on the server, and the new HTML comes back and is patched into the page.

The component stays in the server's memory for as long as the tab is open. It calls the app's services directly: there is no API route, no JSON endpoint and no `fetch()` between the page and the code that answers it. The browser runs one small script that comes with the package; the app writes no JavaScript of its own.

```php
#[Component]
final class Counter
{
    #[Input]
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }
}
```

```html
<!-- Counter.Template.php -->
<button data-on-click="increment">Clicked {{ $this->count }} times</button>
```

**Status:** proof of concept. The package runs under Tenma only and keeps every open tab's components in the memory of one worker; see [Deployment and limits](guides/deployment.md).

## Install

Live needs PHP 8.5, Tenma and `systonia/waypoint` 0.7 or later:

```bash
composer require systonia/waypoint-live
```

Register the plugin with the components the app has. Only the classes listed here can be created; the browser names them by their tag and never by class:

```php
$app->plugin(new LivePlugin([Counter::class]));
```

The layout loads Waypoint's script and the plugin's asset tags, which bring `live.js`:

```php
<?= $this->waypointJsTag() ?>
<?= $this->pluginAssetTags() ?>
```

For [static analysis](guides/static-analysis.md), add `phpstan/phpstan` as a dev dependency; with `phpstan/extension-installer` the package's PHPStan extension is included on its own.

## A first component

A small app with one page and a counter on it. The project has this layout:

```
composer.json        "autoload": {"psr-4": {"App\\": "src/"}}
index.php
src/Counter.php
src/Counter.Template.php
src/HomeController.php
views/_Layout.php
views/Home.php
```

The component is a class with the `#[Component]` attribute. `#[Input]` marks a property the page may set; everything else is the component's own state:

```php
<?php
// src/Counter.php

namespace App;

use Waypoint\Live\{Component, Input};

#[Component]
final class Counter
{
    #[Input]
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function reset(): void
    {
        $this->count = 0;
    }
}
```

The template sits next to the class and is named after it. `$this` is the component, `{{ }}` prints a value HTML-escaped, and `data-on-click` names the method a click calls:

```html
<!-- src/Counter.Template.php -->
<p>Clicked {{ $this->count }} times</p>
<button data-on-click="increment">+1</button>
<button data-on-click="reset">Reset</button>
```

A view places the component by its tag. The tag is `app-` and the class name in kebab case, and its attributes set the inputs:

```html
<!-- views/Home.php -->
<h1>Counter</h1>
<app-counter count="3"></app-counter>
```

```php
<!-- views/_Layout.php -->
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Counter</title>
</head>
<body>
<?= $content ?>
<?= $this->waypointJsTag() ?>
<?= $this->pluginAssetTags() ?>
</body>
</html>
```

```php
<?php
// src/HomeController.php

namespace App;

use Waypoint\Attributes\{Controller, Get};
use Waypoint\Http\View;

#[Controller]
final class HomeController
{
    #[Get('/')]
    public function home(): View
    {
        return new View('Home');
    }
}
```

```php
<?php
// index.php

require __DIR__ . '/vendor/autoload.php';

use App\{Counter, HomeController};
use Waypoint\Live\LivePlugin;
use Waypoint\Options\RendererOptions;
use Waypoint\Waypoint;

$app = Waypoint::create();
$app->plugin(new LivePlugin([Counter::class]));
$app->configure(function (RendererOptions $options) {
    $options->directory = __DIR__ . '/views';
    $options->layout = '_Layout.php';
});
$app->attach([HomeController::class]);

Tenma::serve(8080, $app);
```

Run it and open `http://localhost:8080`:

```bash
tenma index.php
```

What happens:

1. The page's response contains `<app-counter count="3"></app-counter>`. Live creates a `Counter`, sets `$count` to 3, renders the template and puts the result into the tag: `<app-counter data-live data-inputs="{&quot;count&quot;:&quot;3&quot;}"><p>Clicked 3 times</p>...`. Without JavaScript the page stays like that.
2. `live.js` opens a WebSocket to `/_live`. The server creates the component again from the same inputs and keeps it in memory for this tab.
3. A click on **+1** sends `increment` over the WebSocket. The server calls `$counter->increment()`, renders the template again and sends the HTML back, and the browser patches it into the page.

The same component in a PHPUnit test, without a browser (see [Testing](guides/testing.md)):

```php
$tab = Tab::for($app);
$counter = $tab->mount('app-counter', ['count' => 3]);
$counter->click('increment')->assertSee('Clicked 4 times');
```

During development, start with `APP_ENV=development tenma --watch index.php`: a saved template shows at once in the open tab, and errors show in the page and in the editor ([Development tools](guides/development.md)).

## How these docs are organised

- **[Guides](guides/index.md)** explain one part of Live each, with examples that were run against the package: components, templates, events, child components, forms, services, the connection, navigation, uploads, server push, reconnects, development tools, static analysis, testing, security and deployment.
- **[Reference](reference/index.md)** has one page per public class, generated from the code with `tenma docs` (`composer reference` runs it): every attribute, method and option with its signature.

Start with [Components](guides/components.md), [Templates](guides/templates.md) and [Events and actions](guides/events.md); they cover what every component uses. Read [Security model](guides/security.md) before a component shows data that is not for everyone.
