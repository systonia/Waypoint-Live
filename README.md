# Waypoint Live

Live components for [Waypoint](https://github.com/systonia/Waypoint) under [Tenma](https://tenma.dev): the
model of Blazor Server in PHP. A component is a PHP class with state and actions. It renders into the page
like any view, and after that its events and new renders travel over one WebSocket per browser tab. The
component calls services directly; there is no API route and no `fetch()` in between.

**Status: proof of concept.** Requires Waypoint with `Response::upgrade()` (not released yet) and Tenma.

```php
// Counter.Component.php
#[Component(selector: 'app-counter', template: 'Counter.Template.php')]
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

```php
<!-- Counter.Template.php: $this is the component; {$...} is escaped -->
<button data-on-click="increment">{$this->count}</button>
```

```php
$app->plugin(new LivePlugin([Counter::class]));   // only listed components can be created
```

```php
<?= $this->live('app-counter', ['count' => 3]) ?>        <!-- in a view -->
<?= $this->waypointJsTag() ?><?= $this->pluginAssetTags() ?>   <!-- in the layout -->
```

The file names `Counter.Component.php` and `Counter.Template.php` don't fit PSR-4: load components with a
Composer `classmap` (see the demo). The template path is relative to the component's file.

## How it works

1. `$this->live()` creates the component, sets its `#[Input]` properties and renders the template into the
   page, wrapped in its selector: `<app-counter data-live data-inputs="{...}">`. Without JavaScript the page
   stays as rendered.
2. `live.js` opens a WebSocket to `/_live`, a normal Waypoint route: the app's middlewares and guards run, and
   the connection is refused unless its `Origin` is this site.
3. The server creates every component again with the same inputs (a "circuit" per tab) and keeps it in memory
   until the tab closes.
4. `data-on-click="method"` (with an optional `data-arg`), `data-on-input` (the field's value, debounced),
   `data-on-change` (value, or `checked` for a checkbox) and `data-on-submit` (the form as an object) call the
   component's method. After each call the template renders again, and the client patches the new HTML in
   place. The focused field keeps what is being typed.
5. `#[Every(1.0)]` on a method runs it on a timer and pushes a render. Timers stop when the tab closes.

## Templates

A template is a PHP file rendered with `$this` as the component, private properties included. `{$expr}` in
the HTML is HTML-escaped; `<?php foreach (...): ?>` and `<?php if (...): ?>` work as in any view; `<?= ?>`
stays raw for HTML you built yourself. Templates are compiled once when the plugin starts.

## Security

- Only components listed in `LivePlugin` can be created, by selector; the browser never names a class.
- The browser can only call public methods that the template names in a `data-on-*` attribute. Any other
  public method stays out of reach.
- Only `#[Input]` properties can be set from outside, and their values come back from the browser when it
  connects: treat them like query parameters, never as proof of identity. The user comes from the upgrade
  request (JWT, session), which the route's guards check.
- Arguments from the browser are bound to the method's parameter types; a mismatch is an error.
- Errors go to the log; the browser only gets `{"error": true}`, and the circuit keeps running.

## Limits of the proof of concept

- One reconnect strategy: a new connection starts the components again from the page's inputs.
- The DOM patch works by position, without keys: reordered lists rewrite their rows.
- No nested components (a selector tag inside a template is not expanded yet), at most 50 per tab, no
  per-circuit services yet.
- A component's injected services must be reachable from a controller (or need no constructor arguments).

## Demo

```sh
cd demo && composer install
tenma index.php    # http://localhost:8090
```
