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
<?php /** @var Counter $this */ ?>
<!-- Counter.Template.php: $this is the component, <?= ?> escapes -->
<button data-on-click="increment"><?= $this->count ?></button>
```

```php
$app->plugin(new LivePlugin([Counter::class]));   // only listed components can be created
```

```php
<app-counter count="3"></app-counter>                     <!-- in a view -->
<?= $this->waypointJsTag() ?><?= $this->pluginAssetTags() ?>   <!-- in the layout -->
```

The file names `Counter.Component.php` and `Counter.Template.php` don't fit PSR-4: load components with a
Composer `classmap` (see the demo). The template path is relative to the component's file.

## How it works

1. The page's tags (or `$this->live('app-counter', ['count' => 3])`) create the component, set its `#[Input]`
   properties and render the template into the page, wrapped in its selector: `<app-counter data-live data-inputs="{...}">`. Without JavaScript the page
   stays as rendered.
2. `live.js` opens a WebSocket to `/_live`, a normal Waypoint route: the app's middlewares and guards run, and
   the connection is refused unless its `Origin` is this site.
3. The server creates every component again with the same inputs (a "circuit" per tab) and keeps it in memory
   until the tab closes; after a dropped connection a while longer (see Reconnect).
4. Children placed by templates get IDs from the server (`c0.1`), so events reach the right instance.
5. `data-on-click="method"` (with an optional `data-arg`), `data-on-input` (the field's value, debounced),
   `data-on-change` (value, or `checked` for a checkbox) and `data-on-submit` (the form as an object) call the
   component's method. After each call the template renders again, and the client patches the new HTML in
   place. The focused field keeps what is being typed.
6. `#[Every(1.0)]` on a method runs it on a timer and pushes a render. Timers stop when the tab closes.

## Templates

A template is a PHP file rendered with `$this` as the component, private properties included. Unlike in a
Waypoint view, `<?= $expr ?>` is HTML-escaped; `<?php echo $html ?>` stays raw for HTML you built yourself.
`<?php foreach (...): ?>` and `<?php if (...): ?>` work as in any view. Templates are compiled once when the
plugin starts.

`$this` is the component at runtime without any declaration. For the IDE (completion, F12 into the
component) start the template with `<?php /** @var \App\Counter $this */ ?>`: no IDE can learn the type
from the plugin.

## Nested components

A template places other components by their tag:

```php
<!-- CitySearch.Template.php -->
<?php foreach ($this->cities as $city): ?>
    <app-city-row key="<?= $city ?>" city="<?= $city ?>" selected="<?= $city === $this->selected ?>" pick="<?= $this->select(...) ?>"></app-city-row>
<?php endforeach ?>
```

- Attributes set the child's `#[Input]` properties. A value that is a single `<?= $expr ?>` passes the value itself
  (a bool, an object, a closure); anything else passes a string.
- A child keeps its state as long as the parent's template keeps placing it: by `key`, or without one by its
  position among the tags of that selector. A child that is no longer placed is removed, its timers stop.
- On a parent render only inputs whose value changed are set again, as in Angular: the child keeps what it
  changed itself.
- Events go up through a closure input: the child calls `($this->pick)($this->city)`, the parent's method runs.
  That method doesn't need to be in the parent's template, so the browser can't call it directly.
- Every change renders the top-level components again, children included.

## Services per tab

Services come in through `#[Inject]` as in a controller: one instance for the whole app. `#[Scoped]` on a
service class makes it one instance per circuit, that is per browser tab, like a scoped service in Blazor
Server:

```php
#[Scoped]
final class Cart
{
    public array $items = [];
}
```

Every component of the tab that injects `Cart` gets the same instance, children and other top-level components
included; another tab gets its own. It lives as long as the circuit, reconnects included. A scoped service needs
a constructor without required arguments; its own `#[Inject]` properties are set, scoped ones from the same tab.
When an action changes it, every component of the tab that shows it renders again: after a change all
top-level components render, and those whose HTML changed are sent.

## DOM patch

The client patches each render into the page instead of replacing it. Siblings are matched by key and moved,
so a reordered list keeps its elements (and the focus and caret inside them); everything else is
matched by order. A child component's wrapper is keyed by its ID automatically; any other element gets a key
with `data-key`:

```php
<?php foreach ($this->todos as $todo): ?>
    <li data-key="<?= $todo->id ?>"><?= $todo->title ?></li>
<?php endforeach ?>
```

Unkeyed siblings are matched among those of the same tag, so a message showing up in front of an input
leaves the input alone. A form field keeps what the user typed or picked until the server renders a different
value for it; while it has the focus, only if the server already had what is in it, so a render that lags
behind the typing loses nothing. Typing is sent after 150 ms, or right before any other event.

## Reconnect

When the connection drops, the server keeps the tab's circuit for 3 minutes (at most 100 per worker, the
oldest goes first; one without components is not kept), with its timers paused. The browser reconnects after
1, 2, 4 ... at most 30 seconds and sends the circuit's token; the server hands back the same components with
their state, renders them once to catch up and starts the timers again. Events from the time offline are
queued and sent afterwards. If the circuit is gone, the components start again from the page's inputs and the
queued events are dropped. A reload is a new circuit, as in Blazor.

The token lives only in the page's memory and is a bearer secret for the circuit: whoever has it gets the
components while they are parked. It is never in a URL, a cookie or storage.

## Security

- Only components listed in `LivePlugin` can be created, by selector; the browser never names a class.
- The browser can only call public methods that the template names in a `data-on-*` attribute. Any other
  public method stays out of reach.
- Only `#[Input]` properties can be set from outside, and their values come back from the browser when it
  connects: treat them like query parameters, never as proof of identity. The user comes from the upgrade
  request (JWT, session), which the route's guards check.
- Arguments from the browser are converted to the method's parameter types as in a call without
  `strict_types`: `"7"` fills an `int`, `"false"` fills a `bool` as `true`. One that can't be converted is an
  error. Check the values in the method like any other request input.
- Errors go to the log; the browser only gets `{"error": true}`, and the circuit keeps running. A component
  whose render fails gets `data-live-error` and holds back no other component of the tab.

## Limits of the proof of concept

- A circuit lives in the memory of one worker. With several workers a reconnect can land on another one and
  starts over; so does a restart or a deploy.
- Every change renders every top-level component of the tab; fine for small pages, not for big ones.
- Tags need attributes in double quotes and an empty body (no content projection). In a Waypoint view the
  rendered HTML page is searched for them. An attribute that is no `#[Input]` (`class`, `id`) stays on the
  tag; an input arrives as a string (`count="3"` still fills an `int`), so a `bool`, an array or an object
  needs `$this->live()`. A tag inside `<script>`, `<textarea>` or an HTML comment is expanded too.
- At most 50 top-level components per tab.
- The circuit's token isn't tied to the session or the user of the connection that created it: a tab left
  open across a logout and a login of someone else reconnects to the old components.
- The server keeps a circuit only once it notices that the old connection is dead. A browser that notices
  first (a network switch) may reconnect before that and start over.
- A `//` comment inside `<?= ?>` breaks the template; use `/* */`.
- `data-on-submit` sends the form as an object: a repeated name (checkbox group, `select multiple`) keeps its
  last value, files are not sent.
- An event sent while the connection is dying unnoticed (no ping yet) is lost.

## Demo

```sh
cd demo && composer install
tenma index.php    # http://localhost:8090
```

## License

MIT or Apache-2.0, at your option.
