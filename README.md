# Waypoint Live

Live components for [Waypoint](https://github.com/systonia/Waypoint) under [Tenma](https://tenma.dev): the
components run on the server, in PHP. A component is a PHP class with state and actions. It renders into the page
like any view, and after that its events and new renders travel over one WebSocket per browser tab. The
component calls services directly; there is no API route and no `fetch()` in between.

**Status: proof of concept.** Requires PHP 8.5, Waypoint 0.7 or later and Tenma.

**Documentation:** [docs/](docs/index.md): a first component end to end, [guides](docs/guides/index.md) for every
part of Live, and the [reference](docs/reference/index.md) of every public class.

```php
// src/Counter.php
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

```php
<!-- src/Counter.Template.php: $this is the component, {{ }} escapes -->
<button data-on-click="increment">{{ $this->count }}</button>
```

```php
$app->plugin(new LivePlugin([Counter::class]));   // only listed components can be created
```

```php
<app-counter count="3"></app-counter>                     <!-- in a view -->
<?= $this->waypointJsTag() ?><?= $this->pluginAssetTags() ?>   <!-- in the layout -->
```

The selector is `app-` and the class name in kebab case (`CitySearch` is `<app-city-search>`), the template
`Counter.Template.php` next to the class. Both can be given, the template path relative to the class's file:

```php
#[Component(
    selector: 'shop-cart',
    template: 'templates/Cart.php'
)]
```

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
   component's method. `data-on-click="remove($item)"` passes arguments of any type, objects included: they
   stay on the server, the browser only gets an ID for the call. What the event brings comes after them, so
   `data-on-input="rename($item)"` calls `rename($item, $value)`. Use `'` for strings inside the attribute. A call keeps its ID
   across renders; an enum case or an object of a `readonly` class keeps it even when the render builds it again. After each call the template renders again, and the client patches the new HTML in
   place. The focused field keeps what is being typed.
6. `#[Every(1.0)]` on a method runs it on a timer and pushes a render. Timers stop when the tab closes.

## Templates

A template is a PHP file rendered with `$this` as the component, private properties included. Unlike in a
Waypoint view, `<?= $expr ?>` is HTML-escaped; `<?php echo $html ?>` stays raw for HTML you built yourself.
`<?php foreach (...): ?>` and `<?php if (...): ?>` work as in any view. Templates are compiled once when the
plugin starts.

`$this` is the component at runtime without any declaration, and in the editor too through `tenma lsp`
(see In the editor). Without it, a first line `<?php /** @var \App\Counter $this */ ?>` tells the IDE.

`<?= $value ?>` escapes every value except a `Waypoint\Html`, which carries markup that was built safely
(another template's output, say) and is printed as it is.

### Directives

Templates also take directives, mixed with plain PHP as you like:

```
{{/* every product, or a note */}}
@foreach ($this->products as $product)
    <li data-key="{{ $product->id }}">
        {{ $product->name }}
        @if ($product->stock === 0)
            <em>sold out</em>
        @elseif ($product->stock < 5)
            <em>few left</em>
        @else
            {{ $this->badge }}
        @endif
    </li>
@endforeach
<app-cart-row key="{{ $row->id }}" pick="{{ $this->pick(...) }}"></app-cart-row>
```

- `{{ expr }}` prints escaped, as `<?= ?>`; a lone `{{ expr }}` in a child tag's attribute passes the value itself.
  A value of type `Waypoint\Html` prints as it is (`$this->badge` above is one): whether markup is raw
  depends on the value, never on how the template prints it. `{{/* */}}` is a comment.
- `@if (...)`, `@elseif (...)`, `@else`, `@endif`, `@foreach (...)`, `@endforeach`. `@@` prints an `@`.
- A directive starts a line or follows whitespace or `>`, so `info@example.com`, `@media` in CSS and
  `@click` stay text. Inside `<?php ?>` nothing is a directive.
- `@code ... @endcode` holds PHP, as `<?php ... ?>`: `@code $order = $this->order() @endcode`. Neither may
  declare anything (`use`, `namespace`, `declare`, a named function or class) or hold a `break` outside a loop,
  a `yield` outside a function or an assignment to `$this`: PHP would refuse those with a fatal error at the
  first render, so the template does not compile.
- A template resolves class names as its component's class file does: same namespace, same `use` statements.
  `@use (App\Ordering\OrderStatus)` or `@use (App\Ordering\Pickup as Slot)` adds one for the template.
- `<li class="row" @class.late="$order->late">` adds `late` to the class while the condition holds; the name
  runs to the first `=` outside `[ ]`, so Tailwind classes like `md:w-1/2` work. The attribute's own classes
  go through the same `classes()`, so each class comes once.
  `classes('row', ['late' => $order->late])` builds the same list in PHP, in every template without an import.
- A block that does not close, or closes another one, keeps the template from compiling, with its line; so does
  a `/*` without its `*/` inside `{{ }}`, which would comment out the template up to the next `*/`. In the
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
- On a parent render only inputs whose value changed are set again: the child keeps what it
  changed itself.
- Events go up through a closure input: the child calls `($this->pick)($this->city)`, the parent's method runs.
  That method doesn't need to be in the parent's template, so the browser can't call it directly.
- Every change renders the top-level components again, children included.

## Services per tab

Services come in through `#[Inject]` as in a controller: one instance for the whole app. `#[Scoped]` on a
service class makes it one instance per circuit, that is per browser tab:

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
The other way round is refused: an app-wide service that injects a `#[Scoped]` one, directly or through another
service, would share it between all tabs and users, so the component that injects it fails to be created, with
the chain in the error. Make that service `#[Scoped]` too, or inject the scoped one into the component.
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

Its components name their selector with a prefix of the plugin (`selector: 'home-login'`), so that they never
meet an app's `app-` ones.

## The connection

A component or a `#[Scoped]` service can inject `Waypoint\Live\Connection`: the request the WebSocket came in
with, its cookies, and `$request->jwt` as the app's middlewares left it. After a reconnect it is the new
connection's request. `navigate('/path')` sends the browser to another page of the site once the action is
done, for example after a login. Only a path of the site is a destination, never another site. It counts even
if the action throws afterwards, so a check can send a logged-out user to the login and stop the action; during
the page's first render it is an error.

`$request->jwt` is what the middlewares found when the WebSocket connected, not refreshed until a reconnect.
Once its `exp` has passed, the next mount or call closes the connection and the browser reconnects; a
component that must notice a logout or a revoked token right away asks its source again on every action.
Every render of a tab, from a message, a timer or a broadcast, runs with the `FiberLocal` values of its
connection's request: the locale and the request id are the tab's own.

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
queued events are dropped. A reload is a new circuit.

The token lives only in the page's memory and is a bearer secret for the circuit: whoever has it gets the
components while they are parked, but only as the same user. A circuit resumes only if the new connection's
`$request->jwt` names the same `sub` as the one it was opened with; after a logout or for anyone else the
components start over. It is never in a URL, a cookie or storage.

## Security

- Only components listed in `LivePlugin` can be created, by selector; the browser never names a class.
- The guards of a page's route do not protect its components: any browser that reaches `/_live` can mount any
  listed component with any inputs. `#[Authenticated]`, `#[Role]` and `#[Permissions]` on a component class
  are checked when it is created and before every action, on a method before every call of it, against the
  connection's request as for a route. A JWT whose `exp` has passed counts as signed out: the call is refused
  and the connection closed, so the browser reconnects through the middlewares. A rule the attributes cannot
  say (whose record it is) the component checks itself, from its `Connection`.
- The browser can only call public methods that the template names in a `data-on-*` attribute. Any other
  public method stays out of reach, and so does one named only in commented-out markup (`<!-- -->`, `{{/* */}}`). A method the template calls with arguments (`remove($item)`) is reachable
  only through the IDs of the last two renders, with the arguments the template bound and at most the one value
  the event brings; a click on an item that is gone since does nothing.
- Only `#[Input]` properties can be set from outside, and their values come back from the browser when it
  connects: treat them like query parameters, never as proof of identity. The user comes from the upgrade
  request (JWT, session), which the route's guards check.
- A call takes at most one value from the browser, the one its event brings (the field's value, the form,
  `data-arg`). It fills the first parameter the template does not bind; any further one keeps its default. A
  forged message can send it for a click without `data-arg` too, so that parameter is input like any other.
- Values from the browser are converted to the method's parameter types by the rules of `Waypoint\FromArray`:
  `"7"` fills an `int`, `"false"` a `bool` as `false`, `""` a nullable parameter as `null`, `"l"` a backed enum
  as its case, the form's array a `FromArray` DTO (with its `errors`). Nothing is cast: a value that does not
  fit (`"7.5"` for an `int`) is refused without calling the method, logged in dev. Check the values in the
  method like any other request input.
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

## Testing

`Waypoint\Live\Testing\Tab` is a browser tab without a browser, for the app's PHPUnit tests: the components get
the app's services, and every event goes through the template the way the browser sends it, so a test also
proves that the template wires the method up.

```php
use Waypoint\Live\Testing\Tab;

$tab = Tab::for($app);                              // after $app->attach()
$counter = $tab->mount('app-counter', ['count' => 1]);

$counter->click('increment')->assertSee('2');      // the element whose data-on-click names the method
$counter->click('button.reset');                   // or a CSS selector; its data-arg goes along
$counter->input('#search', 'Ber');                 // data-on-input, without the wait
$counter->change('#agree', true);
$counter->submit('form', ['title' => 'Trip']);
$counter->upload('#photo', 'a.jpg', $bytes, 'image/jpeg');
$tab->advance(1.0);                                // #[Every] timers run on fake time

$counter->html();                                  // what the browser has
$counter->component();                             // the object, for its state
$counter->find('app-city-row', key: 'Berlin');     // a child, with the same methods
$tab->assertNavigatedTo('/login');
Tab::page($app, 'app-counter', ['count' => 1]);    // the HTML before any connection
```

`$tab->with($fake)` puts a service into the tab before the first `mount()`, in place of the `#[Scoped]` one it is
or extends (`new class extends Mailer { ... }`); anything else is refused, the app's services come from the app. An
error that the browser would only see as `data-live-error` fails the test with its own stack. Two tabs of one
app share its services: a `Broadcast` in one renders the other.

## Static analysis

`vendor/bin/live-check` checks the project's components without running the app: what the app checks in
development (above), and PHPStan on every template, read as the PHP it becomes with `$this` as its component.
An undefined property, a method with the wrong arguments or a typo in the member of a loop variable is reported
at the template's line:

```
$ vendor/bin/live-check
src/Products.Template.php:4: error: Access to an undefined property Demo\Product::$nmae.
live-check: 1 errors, 0 warnings
```

It needs `phpstan/phpstan` as a dev dependency and uses the project's `phpstan.neon` (level, extensions), or
level 6 without one. The generated files go to the system's temp directory, never into the project; the exit code
fails a CI step. In a `tenma.dev.php` hook, PHPStan's findings reach the editor as you save:

```php
new Tenma\Hook(
    name: 'live-check',
    inputs: ['src/**'],
    run: fn () => Waypoint\Live\Check::publish(__DIR__),
),
```

The package also brings a PHPStan extension (included by `phpstan/extension-installer`): a private property or
method of a `#[Component]` class that only its template uses no longer counts as unused.

## Development

With `APP_ENV=development` (Waypoint's `Environment::isDev()`) and `tenma --watch`:

- A saved template renders at once in every open tab, and the components keep their state. A template that no
  longer compiles keeps the last one that did.
- A saved PHP file restarts the workers (`--watch`). When it was a component's class, the open pages keep
  their components and these get their state back: the properties with plain values (numbers, strings, arrays
  of them), children by their selector and key. A property the new code no longer has, or whose type no longer
  takes the value, starts over and is logged. Any other file loads the open pages again. Needs a Tenma with
  `Tenma::reloadedBy()`; without it, the pages always load again.
- Every component and its template are checked once the app has started and after every saved template:
  actions the component lacks, attributes that are no input of the child, tags that stay as they are, PHP that
  does not parse, directives that do not close, timers that cannot run, inputs the page cannot set. The
  problems go to the terminal and, through `Tenma::diagnostics()`, to the editor, also for templates that are
  not open. A page that uses a tag close to a selector, or an attribute close to an input, gets a warning in
  the log.
- An error shows in the page: what failed, in which component and after which event, the template's lines
  around it and the stack, with links that open the file in the editor. The overlay closes when the component
  renders again. A template that no longer compiles shows there too, until it is saved without the mistake.
  The links open VS Code; for another editor:

  ```php
  $app->configure(fn (LiveOptions $live) => $live->editor = 'vscodium://file/{file}:{line}');
  ```
- The same errors show in the editor at their line, under a Tenma with `Tenma::diagnostics()` and `tenma lsp`,
  until the component renders again or the template compiles.

- `localStorage.liveDebug = '1'` (or `?live-debug` in the URL) logs every action in the browser's console:
  what caused it, its time on the server and the round trip, and per component the render time, the size of
  what was sent or that nothing changed, and the patch time. `live.inspect('c0')` prints what a component holds
  on the server: inputs, state, children, timers and the tab's services (by class, not their content).
- The console and the log warn when an action takes more than 50 ms, a message is larger than 100 KB, a tab
  has more than 30 components, or timers send more than 10 renders a second.

In production none of this happens: the browser only learns that a component failed (`data-live-error`).

## Limits of the proof of concept

- A circuit lives in the memory of one worker. With several workers a reconnect can land on another one and
  starts over; so does a restart or a deploy.
- Every change renders every top-level component of the tab; fine for small pages, not for big ones.
- Tags need attributes in double quotes and an empty body (no content projection). In a Waypoint view the
  rendered HTML page is searched for them. An attribute that is no `#[Input]` (`class`, `id`) stays on the
  tag; an input arrives as a string (`count="3"` still fills an `int`), so a `bool` or an array needs
  `$this->live()`. A page's inputs travel through the browser and back, so they are JSON values only: an object
  is refused, pass its ID and load it in the component. A child placed by a template gets any value, objects
  and closures included. A tag inside `<script>`, `<textarea>` or an HTML comment is expanded too.
- At most 50 top-level components per tab.
- The server keeps a circuit only once it notices that the old connection is dead. A browser that notices
  first (a network switch) may reconnect before that and start over.
- A `//` comment inside `<?= ?>` breaks the template; use `/* */`.
- `data-on-submit` sends the form as an object: `name[]` collects a list, any other repeated name (checkbox
  group, `select multiple`) keeps its last value. The button that submitted it is in with its name and value.
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
