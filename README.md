# Waypoint Live

Live components for [Waypoint](https://github.com/systonia/Waypoint) under [Tenma](https://tenma.dev): the
model of Blazor Server in PHP. A component is a PHP class with state and actions. It renders into the page
like any view, and after that its events and new renders travel over one WebSocket per browser tab. The
component calls services directly; there is no API route and no `fetch()` in between.

**Status: proof of concept.** Requires Waypoint with `Response::upgrade()` (not released yet) and Tenma.

```php
final class Counter extends Component
{
    private int $count = 0;

    #[Action]
    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return "<button wp-live-click=\"increment\">{$this->count}</button>";
    }
}
```

```php
$app->plugin(new LivePlugin([Counter::class]));   // only listed classes can be created
```

```php
<?= $this->live(Counter::class, ['start' => 3]) ?>      <!-- in a view -->
<?= $this->waypointJsTag() ?><?= $this->pluginAssetTags() ?>   <!-- in the layout -->
```

## How it works

1. `$this->live()` creates the component, calls `mount($props)` and renders it into the page, wrapped in a
   `<div wp-live="Class" wp-live-props="{...}">`. Without JavaScript the page stays as rendered.
2. `live.js` opens a WebSocket to `/_live`, a normal Waypoint route: the app's middlewares and guards run, and
   the connection is refused unless its `Origin` is this site.
3. The server creates every component again with the same props (a "circuit" per tab) and keeps it in memory
   until the tab closes.
4. `wp-live-click="method"` (with an optional `wp-live-arg`), `wp-live-input="method"` (the field's value,
   debounced) and `wp-live-submit="method"` (the form as an object) call `#[Action]` methods. After each
   action the component renders, and the client patches the new HTML in place. The focused field keeps
   what is being typed.
5. `$this->every(1.0, fn () => ...)` runs work on a timer and pushes a render; `$this->changed()` pushes one
   from anywhere else. Timers stop when the tab closes.

## Security

- Only components listed in `LivePlugin` can be created, and only `#[Action]` methods can be called.
- Props come back from the browser when it connects: treat them like query parameters, never as proof of
  identity. The user comes from the upgrade request (JWT, session), which the route's guards check.
- Arguments from the browser are bound to the action's parameter types; a mismatch is an error.
- Errors go to the log; the browser only gets `{"error": true}`, and the circuit keeps running.

## Limits of the proof of concept

- One reconnect strategy: a new connection starts the components again from the page's props.
- The DOM patch works by position, without keys: reordered lists rewrite their rows.
- No nested live components, at most 50 per tab, no per-circuit services yet.
- A component's injected services must be reachable from a controller (or need no constructor arguments).

## Demo

```sh
cd demo && composer install
tenma index.php    # http://localhost:8090
```
