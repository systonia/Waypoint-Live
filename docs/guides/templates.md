---
title: Templates
description: Write a component's template in Waypoint's template syntax, with escaped output, class names from the component's imports, Html for markup you built yourself, and child components.
order: 20
---

A template is a PHP file rendered with `$this` as the component, private properties and methods included. On top of PHP it knows the directives of Waypoint's views: `{{ }}` to print, `{{/* */}}` for comments, `@if` and `@foreach` blocks, `@code` for a few lines of PHP, `@use` to import a class and `@class.name` for classes that come and go. Directives and plain PHP mix freely; each directive becomes PHP on the same line, so error messages and the editor point at the template's own lines.

## A template with everything

A product card. The class:

```php
<?php
// src/ProductCard.php

namespace App;

use Waypoint\Html;
use Waypoint\Live\{Component, Input};

#[Component]
final class ProductCard
{
    #[Input]
    public string $name = '';

    #[Input]
    public int $stock = 0;

    #[Input]
    public string $description = '';

    /** @var list<string> */
    private array $tags = ['tea', 'organic'];

    /** The description's lines as paragraphs: escaped first, then the markup added. */
    private function description(): Html
    {
        $paragraphs = array_map(static fn (string $line) => '<p>' . htmlspecialchars($line) . '</p>', explode("\n", $this->description));
        return new Html(implode('', $paragraphs));
    }
}
```

The template:

```html
{{/* name, stock and the description; the tags only when there are some */}}
<h3>{{ $this->name }}</h3>
@if ($this->stock === 0)
    <em>Sold out</em>
@elseif ($this->stock < 5)
    <em>Only {{ $this->stock }} left</em>
@else
    <span>In stock</span>
@endif
{{ $this->description() }}
<?php $count = count($this->tags) ?>
@if ($count > 0)
    <ul>
    @foreach ($this->tags as $i => $tag)
        <li>{{ $i + 1 }}/{{ $count }} @@{{ $tag }}</li>
    @endforeach
    </ul>
@endif
<p>Questions? shop@example.com</p>
```

With `name="Green <b>tea</b>"`, `stock="3"` and a two-line description whose second line is `<script>x</script>`, it renders:

```html
<h3>Green &lt;b&gt;tea&lt;/b&gt;</h3>
    <em>Only 3 left</em>
<p>Fresh.</p><p>&lt;script&gt;x&lt;/script&gt;</p>
    <ul>
            <li>1/2 @tea</li>
            <li>2/2 @organic</li>
        </ul>
<p>Questions? shop@example.com</p>
```

## Printing values

`{{ expr }}` prints the value of a PHP expression, HTML-escaped (quotes included). It is the same as `<?= expr ?>`, which a template escapes too, unlike a plain PHP file:

```html
<p>{{ $this->user->name }}</p>
<p><?= $this->user->name ?></p>          <!-- the same -->
<a href="/tasks/{{ $task->id }}" title="{{ $task->title }}">{{ $task->title }}</a>
```

- Strings, numbers and `Stringable` objects print escaped. `null` prints nothing, `true` prints `1`, `false` nothing.
- An array or another object is an error (`A template can only print scalars and Stringable`), at the template's line.
- A value of type `Waypoint\Html` prints as it is (below).
- `<?php echo $value ?>` prints **without** escaping. Use it only for markup that is already safe; `Html` says the same more clearly.
- A `//` comment inside `<?= ?>` or `{{ }}` would also comment out what the compiler adds behind it: use `/* */` there. The template does not compile otherwise, and the editor and the checks report it.

### Html: markup you built yourself

Whether a value prints raw depends on the value, never on how the template prints it. A method that returns `Waypoint\Html` is printed as it is, by `{{ }}` and `<?= ?>` alike:

```php
private function description(): Html
{
    $paragraphs = array_map(static fn (string $line) => '<p>' . htmlspecialchars($line) . '</p>', explode("\n", $this->description));
    return new Html(implode('', $paragraphs));
}
```

`Html` is a promise that the markup is safe. Build it from escaped parts, as above, or from another template's output; anything that came from a user stays a string, so that it is escaped. Waypoint's views treat it the same way, so a method can hand the same `Html` to a view and to a template. `Waypoint\Live\Html` is a subclass of it, kept for templates written before `Waypoint\Html` existed.

## The syntax

The syntax is Waypoint's, the same as in its views: [the syntax of views](https://github.com/systonia/Waypoint/blob/main/docs/guides/views.md#the-syntax) describes every directive, and [its mistakes](https://github.com/systonia/Waypoint/blob/main/docs/guides/views.md#mistakes) what keeps a file from compiling. In short:

| Directive | PHP it becomes |
|---|---|
| `{{/* ... */}}` | nothing: a comment |
| `@if (cond)`, `@elseif (cond)`, `@else`, `@endif` | `<?php if (cond): ?>` and the rest |
| `@foreach (expr)`, `@endforeach` | `<?php foreach (expr): ?>`, `<?php endforeach ?>` |
| `@code` ... `@endcode` | `<?php ... ?>` |
| `@use (App\Status)` | `use App\Status;` for the whole template |
| `@class.late="$cond"` | the class `late` in the tag's `class` attribute while `$cond` is truthy |
| `@@` | a literal `@` |

A directive starts a line or follows whitespace, `>` or `}}`, so `shop@example.com` and `@media` stay text. Directives and plain PHP mix freely: `@foreach ($this->visible() as $task)` and `<?php foreach ($this->visible() as $task): ?>` are the same. A mistake keeps the template from compiling, with its line:

```
Template '/srv/shop/src/ProductCard.Template.php', line 4: @if has no @endif
```

What differs in a component's template:

- `@model`, `@section` and `@yield` belong to views: a template has `$this` as its component and no layout.
- A template cannot declare anything. A `use` or `namespace` keeps it from compiling (it has its component's, and `@use` adds an import), as do a named `function`, `class`, `interface`, `trait` or `enum`, a `break` or `continue` outside a loop, a `yield` outside a function and an assignment to `$this`, in `@code` and in `<?php ?>` alike. A closure (`$format = fn ($n) => ...`) or a method of the component does what a function would.
- `@class.` on a child component's tag keeps the template from compiling: a child takes inputs, so put the class on an element inside its template or pass an input.
- Markup in a comment, `{{/* */}}` or `<!-- -->`, makes no method callable: a `data-on-click` inside one is ignored ([Events and actions](events.md#what-the-browser-can-call)).
- A value the template needs is best a property or method of the component: typed, and seen by the editor and PHPStan without help. `@code` is for small helpers of the markup: `@code $order = $this->order() @endcode`.
- [`classes()`](https://github.com/systonia/Waypoint/blob/main/docs/reference/Waypoint/index.md#classes) builds a `class` value in PHP, `classes('row', ['late' => $order->late])`, in every template without an import. It is `Waypoint\classes()`; `Waypoint\Live\classes()` stays as another name for it.

## Class names in a template

A template resolves names as its component's class file does: it shares the file's namespace and its `use` statements (classes, `use function`, `use const` and group imports). With this class:

```php
<?php
// src/Shop/OrderTracker.php

namespace Bakery\Shop;

use Bakery\Ordering\OrderStatus;
use Waypoint\Live\Component;

#[Component]
final class OrderTracker
{
    // ...
}
```

its template names the enum as the class does, and a class of `Bakery\Shop` without any import:

```html
@if ($order->status === OrderStatus::Pending)
    <p>{{ Receipt::label($order) }}</p>   <!-- Bakery\Shop\Receipt -->
@endif
```

- A name from the root without a leading backslash still means the root's: `Bakery\Ordering\OrderStatus::Pending` works as well as `\Bakery\Ordering\OrderStatus::Pending`.
- A class of the global namespace needs a leading backslash or an import, as in the class file: `new \DateTimeImmutable()`.
- In development a changed `use` in the class file compiles the template again, like a saved template.
- An import that only the template uses looks unused in the class to PHPStan and the editor. `@use` puts it in the template instead.
- The editor resolves these names too, through `tenma lsp`: `OrderStatus::` completes the enum's cases, and hover and F12 lead to the class, for the class file's imports and the template's `@use` alike.

### @use

`@use` imports a class, an enum, an interface or a trait for the whole template:

```html
@use (Bakery\Ordering\OrderStatus)
@use (Bakery\Ordering\Pickup as Slot)

@if ($order->status === OrderStatus::Pending)
    <p>{{ Slot::next()->label() }}</p>
@endif
```

- One name per `@use`, with or without `as`. It counts wherever it stands; put them at the top.
- An `@use` alias wins over an import of the same alias in the class file.
- A name that is no class keeps the template from compiling, as does a second `@use` of the same alias. A class that does not exist gets a warning: the template may name it only in a branch that never runs.

## Conditional classes

A class that depends on a condition is a `@class.` in the tag, with the class name after the dot and the condition as its value:

```html
<li class="row" @class.late="$order->late" @class.bg-green-100="$order->isReady()">
```

renders `<li class="row late">` while `$order->late` is true and the order is not ready. The conditions go into the tag's `class` attribute; without one the tag gets one. The attribute's own classes and those of the conditions go through one `classes()` call: each class comes once, joined by one space, with no space left over, also when the attribute's value is empty or comes from `{{ }}`. A class name may be a number (`@class.123`). When the state changes, the next render sends the new `class`.

- The value is PHP like every expression in a template: `@class.late="$this->isLate()"`. A plain word such as `isLate` is no PHP.
- The class name runs to the first `=` outside square brackets, so every Tailwind class works: `@class.md:w-1/2="..."`, `@class.hover:underline`, `@class.bg-[#ff0000]`, `@class.data-[state=open]:block`, `@class.[&>*]:p-4`, `@class.!font-bold`. The browser never sees `@class.`: the compiler removes it.
- Tailwind reads the template file as text, so it finds these names and generates their CSS as for any other class.
- In a tag over several lines, an error in a condition is reported at the line of the `class` attribute, or of the first `@class.` when there is none.
- `@class.` outside a start tag, without a name or without a value keeps the template from compiling. So does `@class.` on a child component's tag: a child takes inputs, so put the class on an element inside its template or pass an input. Two `class` attributes in a tag with `@class.` are an error, and so is a `class` value without quotes (`class=row`); the same class twice is a warning. The tag's attributes are read as the browser reads them, so `class=` inside another attribute's value (`title="class='x'"`) is no class attribute.

### classes()

For classes computed in PHP or used more than once, [`classes()`](../reference/Waypoint/Live/index.md#classes) builds the same list. Every template can call it without an import, and it works outside templates as `Waypoint\Live\classes()`:

```html
<li class="{{ classes('row', ['late' => $order->late, 'bg-green-100 font-bold' => $order->isReady()]) }}">
```

- A string is always added and may hold several classes (`'row px-4'`).
- In an array, a value under an integer key is always added; a string key is added when its value is truthy. A key may hold several classes.
- Each class comes once, in order, joined by one space; empty strings and `null` add nothing. The result is a plain string: `{{ }}` escapes it like any other.

A few toggles read best as `@class.`; a list computed in PHP or used in several places reads best as `classes()` or a component method that returns it.

## $this and the editor

`$this` is the component at runtime without any declaration. In the editor, `tenma lsp` with the package's provider knows it too: completion, hover and go-to-definition work on `$this->` in a template ([Development tools](development.md#in-the-editor)). Without the language server, a first line tells the IDE:

```php
<?php /** @var \App\ProductCard $this */ ?>
```

## Placing child components

A template places other components by their tag, like a view does. `{{ }}` alone as an attribute's value passes the value itself, not a string:

```html
@foreach ($this->tasks->all() as $task)
    <app-task-row key="{{ $task->id }}" task="{{ $task }}"></app-task-row>
@endforeach
```

[Child components and inputs](children.md) covers this in full.

## When templates compile

Every template is compiled when the first page uses a component, once all selectors are known, so that each template can expand the others' tags. A template that does not compile is an error then; in development a saved template is compiled again at once, and one that no longer compiles keeps its last working version until the mistake is fixed ([Development tools](development.md#hot-reload)).

See also: [Events and actions](events.md), [Static analysis](static-analysis.md).
