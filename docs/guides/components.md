---
title: Components
description: "Declare a live component with #[Component], give it state and inputs, and know when it is created and dropped."
order: 10
---

A live component is a PHP class with the [`#[Component]`](../reference/Waypoint/Live/Component.md) attribute and a template. Its properties are its state, its public methods are what the browser may call (when the template names them), and its template renders the state to HTML. The object lives on the server; the browser only ever has its HTML.

## A component with state

A task list that shows open or finished tasks from an app-wide service:

```php
<?php
// src/TaskList.php

namespace App;

use Waypoint\Attributes\Inject;
use Waypoint\Live\{Component, Input};

#[Component]
final class TaskList
{
    #[Input]
    public string $title = 'Tasks';

    #[Inject]
    private Tasks $tasks;

    private string $filter = 'open';

    public function show(string $filter): void
    {
        $this->filter = $filter;
    }

    public function toggle(Task $task): void
    {
        $task->done = !$task->done;
    }

    public function remove(Task $task): void
    {
        $this->tasks->remove($task);
    }

    /** @return list<Task> */
    private function visible(): array
    {
        return $this->tasks->all(done: $this->filter === 'done');
    }
}
```

```html
<!-- src/TaskList.Template.php -->
<h2>{{ $this->title }}</h2>
<nav>
    <button data-on-click="show" data-arg="open">Open</button>
    <button data-on-click="show" data-arg="done">Done</button>
</nav>
<ul>
@foreach ($this->visible() as $task)
    <li data-key="{{ $task->id }}">
        <input type="checkbox" data-on-change="toggle($task)" @if ($task->done) checked @endif>
        {{ $task->title }}
        <button data-on-click="remove($task)">Delete</button>
    </li>
@endforeach
</ul>
```

`Tasks` and `Task` are plain classes of the app:

```php
<?php
// src/Task.php

namespace App;

final class Task
{
    public function __construct(
        public readonly int $id,
        public string $title,
        public bool $done = false,
    ) {
    }
}
```

```php
<?php
// src/Tasks.php

namespace App;

/** One for the whole app: every tab sees the same tasks. */
final class Tasks
{
    /** @var array<int, Task> */
    private array $tasks = [];

    private int $next = 1;

    public function add(string $title): Task
    {
        return $this->tasks[$this->next] = new Task($this->next++, $title);
    }

    public function remove(Task $task): void
    {
        unset($this->tasks[$task->id]);
    }

    /** @return list<Task> */
    public function all(?bool $done = null): array
    {
        return array_values(array_filter($this->tasks, static fn (Task $t) => $done === null || $t->done === $done));
    }
}
```

What each part does:

- **`$filter`** is private state. It lives as long as the component; the browser cannot read or set it.
- **`$title`** is an [`#[Input]`](../reference/Waypoint/Live/Input.md): the page sets it with `<app-task-list title="Home"></app-task-list>`. Only public, non-static, non-readonly properties with `#[Input]` can be set from outside.
- **`$tasks`** comes from the app's container through Waypoint's `#[Inject]`, as in a controller ([Services](services.md)).
- **`show()`, `toggle()` and `remove()`** are actions: the template names them in `data-on-*` attributes, so the browser may call them. `toggle($task)` and `remove($task)` are bound to the `Task` object on the server; the browser never sees it ([Events and actions](events.md)).
- **`visible()`** is private and only the template calls it. Templates see private members, since they run with `$this` as the component.

After every action the template renders again and the browser receives the new HTML.

## Selector and template

Without arguments, `#[Component]` derives both names from the class:

| Class | Selector (tag) | Template |
|---|---|---|
| `App\Counter` | `app-counter` | `Counter.Template.php` next to `Counter.php` |
| `App\TaskList` | `app-task-list` | `TaskList.Template.php` |
| `App\CitySearch` | `app-city-search` | `CitySearch.Template.php` |

Both can be given. The template path is relative to the class's file:

```php
#[Component(
    selector: 'shop-cart',
    template: 'templates/Cart.php'
)]
final class CartSummary
{
}
```

A selector is a custom element name: lowercase, with at least one dash, and unique in the app. A missing template, a taken selector or a class without `#[Component]` stops the app when it starts, with the class's name, not later on the first page that shows it.

## Registering components

Only listed components can be created. The app lists its own in [`LivePlugin`](../reference/Waypoint/Live/LivePlugin.md):

```php
$app->plugin(new LivePlugin([Counter::class, TaskList::class, CartSummary::class]));
```

A selector that is not listed is refused, whether a page, a template or a WebSocket message asks for it.

### Components of a plugin

A Waypoint plugin that brings live components of its own requires `LivePlugin` and adds them to the [`Components`](../reference/Waypoint/Live/Components.md) service in its `boot()`:

```php
use Waypoint\Live\{Components, LivePlugin};
use Waypoint\Plugin\PluginBase;

final class AccountPlugin extends PluginBase
{
    public function name(): string
    {
        return 'account';
    }

    public function requires(): array
    {
        return [LivePlugin::class];
    }

    public function boot(Components $components): void
    {
        $components->add(LoginForm::class, AccountMenu::class);
    }
}
```

`boot()` takes what it needs as parameters; Waypoint passes them from the container. Components are added at boot: once a page has rendered a component, `add()` throws.

A plugin's components name their selector with a prefix of their own (`selector: 'account-login'`), so that they never meet an app's `app-` ones.

## Placing a component

A view places a component by its tag, with the inputs as attributes in double quotes:

```html
<app-task-list title="Home"></app-task-list>
```

Live finds these tags in every HTML response after it was rendered, and replaces each with the component's HTML. The tag needs an empty body; attributes that are no input (`class`, `id`) stay on the tag. An attribute's value arrives as a string, which PHP converts for a typed property: `count="3"` fills an `int`. A `bool` or an array needs the view helper, which takes the values as they are:

```php
<?= $this->live('app-task-list', ['title' => 'Home']) ?>
```

It returns a `Waypoint\Html`, so the view's escaping `<?= ?>` prints the component's markup as it is.

A page's inputs travel through the browser and back when the WebSocket connects, so they must be JSON values: numbers, strings, booleans, `null` and arrays of them. An object is refused with an error; pass its ID and let the component load it. A template that places a child component can pass any value, objects and closures included ([Child components](children.md)).

The rendered tag looks like this; the browser finds the component by `data-live` and creates it again from `data-inputs`:

```html
<app-task-list data-live data-inputs="{&quot;title&quot;:&quot;Home&quot;}"><h2>Home</h2>...</app-task-list>
```

## Lifecycle

A component is created twice for a page view, and lives on in the second instance:

1. **Page render.** Live creates the component with `new`, sets its `#[Inject]` properties, then its inputs, renders the template into the page and drops the object.
2. **Connect.** `live.js` opens the WebSocket and sends each component's selector and inputs. The server creates the component again the same way, renders it, starts its [timers](push.md) and keeps it in the tab's *circuit*, the set of components and `#[Scoped]` services of one browser tab.
3. **Actions.** Every event calls a method; afterwards every top-level component of the tab renders again, and those whose HTML changed are sent.
4. **Gone.** When the tab closes, the circuit is kept for a while in case the browser comes back ([Reconnects](reconnect.md)). Then its timers stop and the components are dropped. A child component is dropped as soon as its parent's template no longer places it.

Things that follow from this:

- **The constructor runs before services and inputs are set.** It must not take required arguments, and it cannot use `#[Inject]` properties or inputs: they are not set yet. Use it for defaults that need code; compute what depends on services or inputs in a method the template calls, or in the action that needs it.

  ```php
  #[Component]
  final class Clock
  {
      private string $time;

      public function __construct()
      {
          $this->tick();          // fine: needs nothing from outside
      }

      #[Every(1.0)]
      private function tick(): void
      {
          $this->time = date('H:i:s');
      }
  }
  ```

- **The constructor runs once per page view and once per connection.** Work with side effects (writing a record, sending a mail) belongs in an action, not in the constructor.
- **There is no mount or unmount hook.** What a component needs at the start it reads lazily, in the template or in its methods.
- **State lives in memory.** It does not survive a reload of the page, a restart of the worker or a deploy: a reload is a new circuit, which starts from the page's inputs again. State that must last goes into a service backed by a database.

## What a component may hold

Anything PHP can hold: objects, closures, open handles. Nothing is serialized; the object simply stays in the worker's memory. Keep it small all the same: every open tab holds its own components, and every action renders all of the tab's top-level components again.

See also: [`Component`](../reference/Waypoint/Live/Component.md), [`Input`](../reference/Waypoint/Live/Input.md), [`LivePlugin`](../reference/Waypoint/Live/LivePlugin.md), [`Components`](../reference/Waypoint/Live/Components.md).
