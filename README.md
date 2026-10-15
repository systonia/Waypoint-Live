# Waypoint Live

Live components for [Waypoint](https://github.com/systonia/Waypoint) under [Tenma](https://tenma.dev): the
model of Blazor Server in PHP. A component is a PHP class with state and actions. It renders into the page
like any view, and after that its events and new renders travel over one WebSocket per browser tab. The
component calls services directly; there is no API route and no `fetch()` in between.

**Status: proof of concept.** Requires Waypoint with `Response::upgrade()` (not released yet) and Tenma.

```php
// Counter.Component.php
#[Component(
    selector: 'app-counter',
    template: 'Counter.Template.php'
)]
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
   `data-on-change` (value, `checked` for a checkbox, the file for a file input) and `data-on-submit` (the form
   as an object, files included) call the
   component's method. After each call the template renders again, and the client patches the new HTML in
   place. The focused field keeps what is being typed.
6. `#[Every(1.0)]` on a method runs it on a timer and pushes a render. Timers stop when the tab closes.

## Templates

A template is a PHP file rendered with `$this` as the component, private properties included. Unlike in a
Waypoint view, `<?= $expr ?>` is HTML-escaped; `<?php echo $html ?>` stays raw for HTML you built yourself.
`<?php foreach (...): ?>` and `<?php if (...): ?>` work as in any view. Templates are compiled once when the
plugin starts.

`$this` is the component at runtime without any declaration, and in the editor too through `tenma lsp`
(see In the editor). Without it, a first line `<?php /** @var \App\Counter $this */ ?>` tells the IDE.

`<?= $value ?>` escapes every value except a `Waypoint\Live\Html`, which carries markup that was built safely
(another template's output, say) and is printed as it is.

### Directives

Templates also take directives in the style of Blade, mixed with plain PHP as you like:

```
{{-- every product, or a note --}}
@foreach ($this->products as $product)
    <li data-key="{{ $product->id }}">
        {{ $product->name }}
        @if ($product->stock === 0)
            <em>sold out</em>
        @elseif ($product->stock < 5)
            <em>few left</em>
        @else
            {!! $this->badge !!}
        @endif
    </li>
@endforeach
<app-cart-row key="{{ $row->id }}" pick="{{ $this->pick(...) }}"></app-cart-row>
```

- `{{ expr }}` prints escaped, as `<?= ?>`; a lone `{{ expr }}` in a child tag's attribute passes the value itself.
  `{!! expr !!}` prints raw, `{{-- --}}` is a comment.
- `@if (...)`, `@elseif (...)`, `@else`, `@endif`, `@foreach (...)`, `@endforeach`. `@@` prints an `@`.
- A directive starts a line or follows whitespace or `>`, so `info@example.com`, `@media` in CSS and
  `@click` stay text. Inside `<?php ?>` nothing is a directive.
- A block that does not close, or closes another one, keeps the template from compiling, with its line. In the
  editor these show while typing; `@forech` gets a hint and stays text.

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

## Changes for every tab

A tab renders after its own actions and timers. State that tabs share through an app-wide service, such as a chat,
reaches the other tabs with `Broadcast`: `changed()` renders every connected tab, and each sends the
components whose HTML changed.

```php
#[Inject]
public Broadcast $broadcast;

public function say(string $message): void
{
    $this->room->messages[] = $message;
    $this->broadcast->changed();
}
```

Outside a component, `$app->get(Broadcast::class)`. A tab whose connection dropped renders when it comes back.

With several workers, `changed()` goes out through `Tenma::publish()`: every worker renders its tabs, with
`tenma --pubsub redis://host` every process on that Redis too. Each worker has its own memory, though: that is
enough for state in Postgres or Redis. State in memory goes to every worker with its own message, and each worker
renders its tabs once it has it, with `changedHere()`:

```php
// At startup: runs in every worker
Tenma::subscribe('chat', function (string $message) use ($room, $broadcast) {
    $room->messages[] = $message;
    $broadcast->changedHere();
});

// In the action, instead of changed()
Tenma::publish('chat', $message);
```

Under a Tenma without `Tenma::publish()`, `changed()` only renders the tabs of the own worker.

## Components of a plugin

A plugin that brings live components of its own requires `LivePlugin` and adds them in its `boot()`, the way
waypoint-export-fixed adds its writer:

```php
public function requires(): array
{
    return [LivePlugin::class];
}

public function boot(Container $container): void
{
    $container->get(Components::class)->add(Login::class, Dashboard::class);
}
```

## The connection

A component or a `#[Scoped]` service can inject `Waypoint\Live\Connection`: the request the WebSocket came in
with, its cookies, and `$request->jwt` as the app's middlewares left it. After a reconnect it is the new
connection's request. `navigate('/path')` sends the browser to another page of the site once the action is
done, for example after a login. Only a path of the site is a destination, never another site. It counts even
if the action throws afterwards, so a check can send a logged-out user to the login and stop the action; during
the page's first render it is an error.

`$request->jwt` is what the middlewares found when the WebSocket connected, not refreshed until a reconnect: a
component that must notice a logout or a revoked token right away asks its source again on every action.

```php
#[Inject]
public Connection $connection;

public function logout(): void
{
    $this->sessions->end($this->connection->request);
    $this->connection->navigate('/login');
}
```

Live actions need no CSRF token: the WebSocket only connects from the site's own pages (its `Origin` is checked),
so another site never gets to send an action.

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

## Uploads

A file input or a form sends its files along; the method gets a `Waypoint\Http\UploadedFile`, the same class
a controller gets from `$req->file()`:

```php
<input type="file" accept="image/*" data-on-change="pick">

<form data-on-submit="save">
    <input name="title">
    <input type="file" name="photos[]" multiple>
    <button>Save</button>
</form>
```

```php
public function pick(?UploadedFile $file): void              // null when the selection was cleared
{
    $this->preview = $file?->name;
}

/** @param array{title: string, photos?: list<UploadedFile>} $form */
public function save(array $form): void
{
    foreach ($form['photos'] ?? [] as $photo) {
        $photo->moveTo($this->media->pathFor($photo));     // under Tenma without blocking the worker
    }
}
```

Name and type come from the browser, as with any upload: never use the name as a path, and check the content
before trusting the type. An empty file input is left out of the form; `multiple` gives a list. The file's
content lives in memory: move it, don't keep the `UploadedFile` in the component, or it stays for the life of
the circuit. Events keep their order: a click right after picking a file waits until the file is read and sent.
A file the browser can't read marks the component with `data-live-error`.

An element with `data-live-ignore` belongs to the browser once it is on the page: a render doesn't touch it or
what is inside it. For an editor, a map or another widget that keeps its own DOM; put a `data-key` on it so it
moves along with its row.

## Reconnect

When the connection drops, the server keeps the tab's circuit for 3 minutes (at most 100 per worker, the
oldest goes first; one without components is not kept), with its timers paused. The browser reconnects after
1, 2, 4 ... at most 30 seconds and sends the circuit's token; the server hands back the same components with
their state, renders them once to catch up and starts the timers again. Events from the time offline are
queued and sent afterwards. If the circuit is gone, the components start again from the page's inputs and the
queued events are dropped. A reload is a new circuit, as in Blazor.

The token lives only in the page's memory and is a bearer secret for the circuit: whoever has it gets the
components while they are parked, but only as the same user. A circuit resumes only if the new connection's
`$request->jwt` names the same `sub` as the one it was opened with; after a logout or for anyone else the
components start over. It is never in a URL, a cookie or storage.

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

## In the editor

The package brings a provider for `tenma lsp`, Tenma's language server that runs next to the editor's PHP
extension (in VS Code and VSCodium through the Tenma extension, which starts it in trusted workspaces). It finds
the components below the project's `autoload` and `autoload-dev` directories, without running the app, and
gives a template:

- `$this->` completed with the component's properties and methods, private ones included; hover and F12 into the class
- `data-on-click="` completed with the methods the browser may call; F12 to the method
- `<app-` completed with the selectors, the attributes of a child tag with its `#[Input]` properties; F12 from a
  tag to its class
- the variable of a loop over `$this->items` completed with the members of the element type that the
  property's `@var list<Item>` (or `Item[]`, `array<int, Item>`) names
- directives and the PHP inside them coloured, which the editor's PHP grammar sees as text
- while typing: an action the component lacks or cannot be called (`Did you mean add()?`), an attribute that is
  no input of the child, a child tag the compiler would leave as it is, a `//` comment inside `<?= ?>`, PHP that
  does not parse, directives that do not close, each at its line

A changed component is seen once it is saved.

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
- `data-on-submit` sends the form as an object: `name[]` collects a list, any other repeated name (checkbox
  group, `select multiple`) keeps its last value.
- A file travels base64-encoded inside one WebSocket message: a third bigger than the file, at most
  `--max-body` (10 MB by default) per message, and at most 20 files per call.
- An event sent while the connection is dying unnoticed (no ping yet) is lost.

## Demo

```sh
cd demo && composer install
tenma index.php    # http://localhost:8090
```

## License

MIT or Apache-2.0, at your option.
