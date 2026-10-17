---
title: Events and actions
description: Call a component's methods from the browser with data-on-click, -input, -change and -submit, pass values and bound arguments, and know what the browser cannot call.
order: 30
---

An attribute `data-on-<event>="method"` in a template wires a browser event to a method of the component. When the event fires, `live.js` sends the method's name (and at most one value) over the WebSocket; the server calls the method, renders the tab's components again and sends back what changed.

## The four events

| Attribute | Fires on | The method gets |
|---|---|---|
| `data-on-click` | a click on the element | nothing, or the element's `data-arg` as a string |
| `data-on-input` | typing in a field, sent 150 ms after the last keystroke | the field's value |
| `data-on-change` | a field's `change` event | the value; `true`/`false` for a checkbox; an `UploadedFile` (or a list with `multiple`) for a file input |
| `data-on-submit` | a form's submit | the form as an array ([Forms](forms.md)) |

`data-on-click` and `data-on-submit` call `preventDefault()`: a link with `data-on-click` does not follow its `href`, and a form does not post to the server the classic way. Other event names do nothing in the browser.

A quantity field, a checkbox and a button:

```php
<?php
// src/Quantity.php

namespace App;

use Waypoint\Live\Component;

#[Component]
final class Quantity
{
    public int $quantity = 1;

    public bool $gift = false;

    public bool $published = false;

    public function set(int $quantity): void
    {
        $this->quantity = max(1, $quantity);
    }

    public function wrap(bool $gift): void
    {
        $this->gift = $gift;
    }

    public function save(bool $publish = false): void
    {
        $this->published = $publish;
    }
}
```

```html
<!-- src/Quantity.Template.php -->
<input type="number" value="{{ $this->quantity }}" data-on-input="set">
<label><input type="checkbox" data-on-change="wrap" @if ($this->gift) checked @endif> Gift wrap</label>
<button data-on-click="save">Save</button>
<p>{{ $this->quantity }} {{ $this->gift ? 'wrapped' : 'plain' }}</p>
```

Typing `7` calls `set("7")`, which PHP converts to `set(7)` for the `int` parameter (below). Ticking the box calls `wrap(true)`.

### Typing and the order of events

`data-on-input` waits until the user stops typing for 150 ms. Any other event of the page first sends what is still waiting, so a quick click right after typing never overtakes the text. Events go out in the order they happened, also when one first has to read a file ([Uploads](uploads.md)).

## data-arg

A click passes the element's `data-arg` as a string:

```html
<button data-on-click="show" data-arg="open">Open</button>
<button data-on-click="show" data-arg="done">Done</button>
```

```php
public function show(string $filter): void
{
    $this->filter = $filter;
}
```

`data-arg` is good for a fixed value written into the template. For anything that comes from the data (an ID, an object) a bound call is the better choice.

## Bound calls: remove($task)

A `data-on-*` attribute can call a method with arguments:

```html
@foreach ($this->visible() as $task)
    <li data-key="{{ $task->id }}">
        <input type="checkbox" data-on-change="toggle($task)" @if ($task->done) checked @endif>
        {{ $task->title }}
        <button data-on-click="remove($task)">Delete</button>
    </li>
@endforeach
```

```php
public function toggle(Task $task): void
{
    $task->done = !$task->done;
}

public function remove(Task $task): void
{
    $this->tasks->remove($task);
}
```

The arguments are evaluated on the server while the template renders, and they stay there: values of any type, objects and closures included. The browser only gets an ID for the call:

```html
<button data-on-click="remove~e535d152bec9c4f8">Delete</button>
```

A click sends that ID back, and the server calls `remove()` with the very `Task` object the template had. Nothing about the task travels through the browser, so nothing about it can be forged.

- **What the event brings comes after the bound arguments.** `data-on-input="rename($task)"` calls `rename($task, $value)`; `data-on-change="toggle($task)"` on a checkbox calls `toggle($task, true)`, and a method that takes no further parameter ignores it.
- **Strings inside the attribute use single quotes**, since a `"` ends the attribute: `data-on-click="move($task, 'done')"`.
- **The same call gets the same ID in every render.** Values count by value. An enum case, and an object that cannot change (a `readonly` class, or one whose properties are all `readonly`), count by class and properties, so a row that every render builds again from the database keeps its ID. Any other object counts as that very object: a list that is reordered keeps its IDs, a removed item takes its ID along, and an object built again by the next render gets a new ID.
- **A call stays valid for two renders.** The server keeps the bound calls of the last two renders of each component, so a click that was on its way during a render still arrives. A click on an item that is gone since then does nothing.

## After an action

Every action, successful or not, is followed by a render of every top-level component of the tab, children included. The server sends the components whose HTML changed; the browser patches the new HTML into the page:

- Siblings with a `data-key` (and child components, which get one automatically) are matched by key and moved, so a reordered list keeps its elements, and the focus and caret inside them.
- Other siblings are matched in order among those with the same tag, so a message that shows up in front of an input leaves the input alone.
- Form fields keep what the user typed until the server renders a different value for them ([Forms and binding](forms.md#fields-and-the-server)).
- An element with `data-live-ignore` belongs to the browser once it is on the page: a render does not touch it or anything inside. Use it for an editor, a map or another widget that keeps its own DOM, with a `data-key` so it moves along with its row.

```html
@foreach ($this->notes as $note)
    <div data-key="{{ $note->id }}">
        <h3>{{ $note->title }}</h3>
        <div class="editor" data-live-ignore></div>
    </div>
@endforeach
```

## When an action fails

An exception in an action is logged with its stack. The browser only learns that the component failed: its tag gets the attribute `data-live-error`, which a stylesheet can show. The circuit keeps running, the other components of the tab are not affected, and the next successful render removes the attribute. What the method changed before it threw is rendered all the same.

```css
[data-live-error] { outline: 2px solid #e5484d; }
```

In development the error also shows in the page and in the editor ([Development tools](development.md#errors-in-the-page)).

## What the browser can call

The browser names a method; the server decides whether it may:

- **Only methods the template names.** A public method of the class that no `data-on-*` attribute names stays out of reach, even though it is public. A method named only in commented-out markup (`<!-- -->` or `{{/* */}}`) is not named.
- **Only public, non-static methods.** The editor and the checks flag a `data-on-click` that names a private, static or missing method.
- **Bound calls only by their ID.** `remove($task)` is reachable only through the IDs of the last two renders, with the arguments the template bound.

A parent's method that a child calls through a closure input is not named in any template, so the browser cannot call it directly ([Child components](children.md#events-up-to-the-parent)).

## One value from the browser

A call takes at most one value from the browser: the one its event brings (the field's value, the form, `data-arg`). It fills the first parameter the template does not bind; any further parameter keeps its default. A message with more values is refused.

That one value is input like a query parameter. A forged message can send it even for a click that has no `data-arg`, so `save(bool $publish = false)` above can be called as `save("1")` by anyone who writes their own WebSocket messages. Design methods so that this value is harmless or checked:

```php
// The browser can choose $publish: check that the user may publish
public function save(bool $publish = false): void
{
    if ($publish && !$this->mayPublish()) {
        throw new ForbiddenException('Not allowed to publish');
    }
    // ...
}
```

Values are converted to the parameter's type as in a PHP call without `strict_types`:

| Browser sends | Parameter | Method gets |
|---|---|---|
| `"7"` | `int $quantity` | `7` |
| `"abc"` | `int $quantity` | `TypeError`: the component fails |
| `"false"` | `bool $publish` | `true` (a non-empty string) |
| `true` (checkbox) | `bool $gift` | `true` |

Check values in the method as you would any other request input.

See also: [Forms and binding](forms.md), [Security model](security.md), [Testing](testing.md).
