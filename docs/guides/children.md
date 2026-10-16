---
title: Child components and inputs
description: Place components inside a template, pass them values and closures, keep their state across renders with keys, and send events up to the parent.
order: 40
---

A template places other components by their tag, the way a view does. Each child is a component of its own, with its own state and actions, and it lives as long as the parent's template keeps placing it.

## A board with rows

A task board places one row per open task. Each row can be renamed on its own and tells the board when it is done:

```php
<?php
// src/TaskBoard.php

namespace App;

use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

#[Component]
final class TaskBoard
{
    #[Inject]
    private Tasks $tasks;

    private ?string $message = null;

    public function add(string $title): void
    {
        $this->tasks->add($title);
    }

    /** Called by a TaskRow through its $onDone input; no data-on-* names it, so the browser cannot. */
    public function finished(Task $task): void
    {
        $task->done = true;
        $this->message = "Done: $task->title";
    }
}
```

```html
<!-- src/TaskBoard.Template.php -->
<button data-on-click="add" data-arg="Water the plants">Add a task</button>
@if ($this->message !== null)
    <p role="status">{{ $this->message }}</p>
@endif
<ul>
@foreach ($this->tasks->all(done: false) as $task)
    <app-task-row key="{{ $task->id }}" task="{{ $task }}" onDone="{{ $this->finished(...) }}" compact></app-task-row>
@endforeach
</ul>
```

```php
<?php
// src/TaskRow.php

namespace App;

use Closure;
use Waypoint\Live\{Component, Input};

#[Component]
final class TaskRow
{
    #[Input]
    public Task $task;

    /** @var (Closure(Task): void)|null */
    #[Input]
    public ?Closure $onDone = null;

    #[Input]
    public bool $compact = false;

    private bool $editing = false;

    public function edit(): void
    {
        $this->editing = true;
    }

    public function rename(string $title): void
    {
        $this->task->title = $title;
        $this->editing = false;
    }

    public function done(): void
    {
        ($this->onDone)($this->task);
    }
}
```

```html
<!-- src/TaskRow.Template.php -->
<li>
@if ($this->editing)
    <input value="{{ $this->task->title }}" data-on-change="rename">
@else
    <span data-on-click="edit">{{ $this->task->title }}</span>
@endif
    <button data-on-click="done">Done</button>
</li>
```

Both classes are listed in `LivePlugin`; a child is created like any component, so only listed ones can be placed.

The board renders each row inside its tag, with an ID the server gives it:

```html
<app-task-row data-live data-live-id="c0.1"><li>
    <span data-on-click="edit">Water the plants</span>
    <button data-on-click="done">Done</button>
</li>
</app-task-row>
```

A click inside the row goes to the row, by that ID: the browser sends events to the innermost component around the element.

## Passing inputs

Attributes set the child's `#[Input]` properties:

| Attribute | The child gets |
|---|---|
| `task="{{ $task }}"` | the value itself: here the `Task` object |
| `onDone="{{ $this->finished(...) }}"` | the closure |
| `title="Task {{ $n }}"` | a string: text and values mixed |
| `title="Fixed"` | the string `Fixed` |
| `compact` (no value) | `true` |
| `key="{{ $task->id }}"` | no input: the child's identity (below) |

A value that is a single `{{ expr }}` (or `<?= expr ?>`) passes the value itself, of any type; anything else passes a string. Attribute names are the property names as written (`onDone`, not `on-done`). An attribute that is no input of the child is an error when the template renders, and the editor and the checks report it with the inputs the child has.

The tag needs double quotes around values and an empty body: `<app-task-row ...></app-task-row>` or `<app-task-row ... />`. A tag in another form stays in the HTML as it is, and the checks report it. There is no content projection: what a child shows comes from its own template.

## Identity and state

A child keeps its object, and with it its state, as long as the parent's template places it again:

- **With `key`** the child is identified by its selector and key. A row whose task moves to another position keeps its state (`$editing` above), and so do its siblings when a row above them goes.
- **Without `key`** the child is identified by its position among the tags of that selector in the parent's template. That is fine for a fixed set of children; for a list it mixes up state as soon as an item is inserted or removed.
- **Two children with the same key** in one render are an error. A key must be a string or a number.
- **A child that is no longer placed is removed**: its timers stop, and placing it again later creates a new one.

Use a key for every child placed in a loop.

### Inputs on later renders

On each render of the parent, only inputs whose value changed are set again. A child that changed one of its own inputs keeps that change until the parent passes a different value. Values compare with `===`: the same object or an equal string counts as unchanged, while a new closure from `$this->finished(...)` is a new value each time and is set again, which does no harm.

## Events up to the parent

A child tells its parent about something through a closure input. The parent passes one of its methods as a first-class callable, and the child calls it:

```html
<app-task-row ... onDone="{{ $this->finished(...) }}"></app-task-row>
```

```php
public function done(): void
{
    ($this->onDone)($this->task);
}
```

A click on the row's **Done** calls `TaskRow::done()`, which runs `TaskBoard::finished($task)`. No template names `finished`, so the browser cannot call it directly: only the row can, with its own task. A closure is a good way to give a child exactly one capability of the parent.

After the action the whole tab renders again, parent included, so the board shows its message and the finished row disappears from the list.

## Renders

Every change renders every top-level component of the tab again, with all its children. A child has no render of its own: its HTML travels inside its top-level component's. This keeps state shared between components consistent without bookkeeping, and is fine for pages of moderate size; see [Deployment and limits](deployment.md#limits).

## Testing children

[`Mounted::find()`](../reference/Waypoint/Live/Testing/Mounted.md#find) reaches a child by selector and key, with the same methods as the top-level component:

```php
$board = Tab::for($app)->mount('app-task-board');
$row = $board->find('app-task-row', 1);
$row->click('edit');
$row->change('input', 'Buy oat milk');
$board->assertSee('Buy oat milk');
$board->find('app-task-row', 1)->click('done');
$board->assertSee('Done: Buy oat milk');
```

See also: [Components](components.md), [Events and actions](events.md), [Testing](testing.md).
