---
title: "Waypoint\\Live"
description: "The classes, interfaces, traits, enums and functions in Waypoint\\Live."
order: 200
generated: true
---

## Namespaces

- [`Waypoint\Live\Testing`](Testing/index.md)

## Types

- [`Broadcast`](Broadcast.md) · final class: Renders every connected tab again: for state that tabs share through an app-wide service, such as a chat.
- [`Check`](Check.md) · final class: `vendor/bin/live-check`, the same as `vendor/bin/waypoint-check`: the components of a project, and its views, checked without running the app.
- [`Component`](Component.md) · final class: Marks a class as a live component, whose state stays on the server while its template shows in the browser.
- [`Components`](Components.md) · final class: The app's live components: those given to LivePlugin, and those another plugin adds in its boot(), which takes this service as a parameter: `boot(Components $components)`, then `$components->add(StampCard::class)`.
- [`Connection`](Connection.md) · final class: The browser connection of a circuit, for a component or a #[Scoped] service that injects it: the request the WebSocket came in with (its cookies, and $request->jwt as the app's middlewares left it), and a way to send the browser to another page.
- [`Every`](Every.md) · final class: Runs the method every $seconds while the browser is connected and pushes a new render afterwards.
- [`Html`](Html.md) · final readonly class: Markup a template prints as it is: the same as `Waypoint\Html`, which templates and views print as it is too.
- [`Input`](Input.md) · final class: A public property the page sets when it places the component: `$this->live('app-add-to-cart', ['product' => 3])`.
- [`LiveOptions`](LiveOptions.md) · class: Live's options, set with `$app->configure(fn (LiveOptions $live) => $live->editor = '...')`.
- [`LivePlugin`](LivePlugin.md) · final class: Register with `$app->plugin(new LivePlugin([AddToCart::class, CartBadge::class]))`: classes with a #[Component] attribute, and only these can be created; the browser names them by selector.
- [`Scoped`](Scoped.md) · final class: One instance per circuit (browser tab) instead of one per app: a cart, a wizard's progress, a tab's filters.
- [`TemplateChecks`](TemplateChecks.md) · final class: The components' templates in `vendor/bin/waypoint-check` (composer.json names it under extra.waypoint.checks): what Problems finds, and each template as the PHP PHPStan reads, with `$this` as its component, private members included.

## Functions

### classes()

```php
function classes(array|string ...$parts): string
```

The same as `Waypoint\classes()`, which every template and view calls without an import; this name stays for
code written before it.

**Parameters**

- `...$parts` `string|array<array-key, mixed>`

**Returns** `string`
