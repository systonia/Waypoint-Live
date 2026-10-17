---
title: Templates
description: Write a component's template in Waypoint's template syntax, with escaped output, class names from the component's imports, Html for markup you built yourself, and child components.
order: 20
---

A template is a PHP file rendered with `$this` as the component, private properties and methods included. On top of PHP it knows the directives of Waypoint's views: `{{ }}` to print, `{{/* */}}` for comments, `@if` and `@foreach` blocks, `@code` for a few lines of PHP, `@use` to import a class and `@class.name` for classes that come and go. Directives and plain PHP mix freely; each directive becomes PHP on the same line, so error messages and the editor point at the template's own lines.

## A template with everything

A product card, for a shop page that shows one product with a few facts. The bakery prints its cards in a view, `views/Shop/Catalog.php`; as a component the card looks like this. The class:

```php
<?php
// src/Components/ProductCard.php

namespace Bakery\Components;

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
    private array $tags = ['wholegrain', 'organic'];

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
    <em>Sold out for today</em>
@elseif ($this->stock < 5)
    <em>Only {{ $this->stock }} left</em>
@else
    <span>Baked this morning</span>
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
<p>Questions? hello@sonnenkorn.test</p>
```

With `name="Rye <b>bread</b>"`, `stock="3"` and a two-line description whose second line is `<script>x</script>`, it renders:

```html
<h3>Rye &lt;b&gt;bread&lt;/b&gt;</h3><em>Only 3 left</em><p>Sourdough, 24 hours.</p><p>&lt;script&gt;x&lt;/script&gt;</p>
<ul><li>1/2 @wholegrain</li><li>2/2 @organic</li></ul><p>Questions? hello@sonnenkorn.test</p>
```

The directives' lines leave no whitespace behind ([Whitespace](#whitespace)); the line break after `{{ $this->description() }}` stays, as text does.

## Printing values

`{{ expr }}` prints the value of a PHP expression, HTML-escaped (quotes included). It is the same as `<?= expr ?>`, which a template escapes too, as Waypoint's views do:

```html
<p class="font-medium">{{ $order->customerName }}</p>
<p class="font-medium"><?= $order->customerName ?></p>          <!-- the same -->
<a href="/products/{{ $product->slug }}" title="{{ $product->name('en') }}">{{ $product->name('en') }}</a>
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
| `@use (Bakery\Models\OrderStatus)` | `use Bakery\Models\OrderStatus;` for the whole template |
| `@class.current="$cond"` | the class `current` in the tag's `class` attribute while `$cond` is truthy |
| `@@` | a literal `@` |

A directive starts a line or follows whitespace, `>` or `}}`, so `hello@sonnenkorn.test` and `@media` stay text. Directives and plain PHP mix freely: `@foreach ($this->cart->lines() as $line)` and `<?php foreach ($this->cart->lines() as $line): ?>` are the same. A mistake keeps the template from compiling, with its line:

```
Template '/srv/sonnenkorn/src/Components/ProductCard.Template.php', line 3: @if has no @endif
```

What differs in a component's template:

- `@model`, `@section` and `@yield` belong to views: a template has `$this` as its component and no layout.
- A template cannot declare anything. A `use` or `namespace` keeps it from compiling (it has its component's, and `@use` adds an import), as do a named `function`, `class`, `interface`, `trait` or `enum`, a `break` or `continue` outside a loop, a `yield` outside a function and an assignment to `$this`, in `@code` and in `<?php ?>` alike. A closure (`$format = fn ($n) => ...`) or a method of the component does what a function would.
- `@class.` on a child component's tag keeps the template from compiling: a child takes inputs, so put the class on an element inside its template or pass an input.
- Markup in a comment, `{{/* */}}` or `<!-- -->`, makes no method callable: a `data-on-click` inside one is ignored ([Events and actions](events.md#what-the-browser-can-call)).
- A value the template needs is best a property or method of the component: typed, and seen by the editor and PHPStan without help. `@code` is for small helpers of the markup, as at the top of the bakery's `OrderTracker.Template.php`: `@code $order = $this->order() @endcode`.
- [`classes()`](https://github.com/systonia/Waypoint/blob/main/docs/reference/Waypoint/index.md#classes) builds a `class` value in PHP, `classes('step', ['current' => $step === $order->status])`, in every template without an import. It is `Waypoint\classes()`; `Waypoint\Live\classes()` stays as another name for it.

### Whitespace

A directive on its own line leaves no whitespace behind: its indentation, the line break after it, blank lines between directives and the whitespace at the start and end of a block's content print nothing, so a template can be indented freely. This holds for `@if`, `@elseif`, `@else`, `@endif`, `@foreach`, `@endforeach`, `@code`, `@endcode` and `@use`. Whitespace without a line break stays as written, so `Hello @if ($name) {{ $name }} @endif` keeps its spaces. Around `{{ }}` and plain text, whitespace goes only where it touches a directive.

## Class names in a template

A template resolves names as its component's class file does: it shares the file's namespace and its `use` statements (classes, `use function`, `use const` and group imports). The bakery's `src/Components/OrderTracker.php` starts like this:

```php
<?php

namespace Bakery\Components;

use Bakery\Models\{Order, OrderStatus};
use Bakery\Repositories\Orders;
use Bakery\Services\Format;
use Waypoint\Live\{Broadcast, Component, Input};

#[Component]
final class OrderTracker
{
    // ...
}
```

so its template names the enum and the formatter as the class does, without an import of its own. A class of `Bakery\Components` would need none either:

```html
@if ($order->status === OrderStatus::Pending)
    <div class="rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200">
        ...
    </div>
@endif
<td class="py-2 text-right tabular-nums">{{ Format::price($line->totalCents(), $this->i18n->locale()) }}</td>
```

- A name from the root without a leading backslash still means the root's: `Bakery\Models\OrderStatus::Pending` works as well as `\Bakery\Models\OrderStatus::Pending`.
- A class of the global namespace needs a leading backslash or an import, as in the class file: `new \DateTimeImmutable()`.
- In development a changed `use` in the class file compiles the template again, like a saved template.
- An import that only the template uses looks unused in the class to PHPStan and the editor. `@use` puts it in the template instead.
- The editor resolves these names too, through `tenma lsp`: `OrderStatus::` completes the enum's cases, and hover and F12 lead to the class, for the class file's imports and the template's `@use` alike.

### @use

`@use` imports a class, an enum, an interface or a trait for the whole template:

```html
@use (Bakery\Models\OrderStatus)
@use (Bakery\Services\Format as F)

@if ($order->status === OrderStatus::Ready)
    <p>
        Ready for pickup on {{ F::day($order->pickupDate, 'en') }}
    </p>
@endif
```

- One name per `@use`, with or without `as`. It counts wherever it stands; put them at the top.
- An `@use` alias wins over an import of the same alias in the class file.
- A name that is no class keeps the template from compiling, as does a second `@use` of the same alias. A class that does not exist gets a warning: the template may name it only in a branch that never runs.

## Conditional classes

A class that depends on a condition is a `@class.` in the tag, with the class name after the dot and the condition as its value. The steps of the bakery's order page could mark the current one like this:

```html
<li class="rounded-xl p-3" @class.bg-amber-800="$step === $order->status" @class.text-stone-500="$step !== $order->status">
```

renders `<li class="rounded-xl p-3 bg-amber-800">` for the step the order is at. The conditions go into the tag's `class` attribute; without one the tag gets one. The attribute's own classes and those of the conditions go through one `classes()` call: each class comes once, joined by one space, with no space left over, also when the attribute's value is empty or comes from `{{ }}`. A class name may be a number (`@class.123`). When the state changes, the next render sends the new `class`.

- The value is PHP like every expression in a template: `@class.ring-2="$this->isToday($order)"`. A plain word such as `isToday` is no PHP.
- The class name runs to the first `=` outside square brackets, so every Tailwind class works: `@class.md:w-1/2="..."`, `@class.hover:underline`, `@class.bg-[#ff0000]`, `@class.data-[state=open]:block`, `@class.[&>*]:p-4`, `@class.!font-bold`. The browser never sees `@class.`: the compiler removes it.
- Tailwind reads the template file as text, so it finds these names and generates their CSS as for any other class.
- In a tag over several lines, an error in a condition is reported at the line of the `class` attribute, or of the first `@class.` when there is none.
- `@class.` outside a start tag, without a name or without a value keeps the template from compiling. So does `@class.` on a child component's tag: a child takes inputs, so put the class on an element inside its template or pass an input. Two `class` attributes in a tag with `@class.` are an error, and so is a `class` value without quotes (`class=row`); the same class twice is a warning. The tag's attributes are read as the browser reads them, so `class=` inside another attribute's value (`title="class='x'"`) is no class attribute.

### classes()

For classes computed in PHP or used more than once, [`classes()`](../reference/Waypoint/Live/index.md#classes) builds the same list. Every template can call it without an import, and it works outside templates as `Waypoint\classes()`. The bakery's `OrderTracker.Template.php` picks one of two sets:

```html
<li data-key="{{ $step->value }}" class="{{ classes('rounded-xl p-3 text-center', $step === $order->status ? 'bg-amber-800 font-semibold text-white' : 'bg-stone-100 text-stone-500') }}">
```

- A string is always added and may hold several classes (`'row px-4'`).
- In an array, a value under an integer key is always added; a string key is added when its value is truthy. A key may hold several classes.
- Each class comes once, in order, joined by one space; empty strings and `null` add nothing. The result is a plain string: `{{ }}` escapes it like any other.

A few toggles read best as `@class.`; a list computed in PHP or used in several places reads best as `classes()` or a component method that returns it.

## $this and the editor

`$this` is the component at runtime without any declaration. In the editor, `tenma lsp` with the package's provider knows it too: completion, hover and go-to-definition work on `$this->` in a template ([Development tools](development.md#in-the-editor)). Without the language server, a first line tells the IDE:

```php
<?php /** @var \Bakery\Components\ProductCard $this */ ?>
```

## Placing child components

A template places other components by their tag, like a view does. `{{ }}` alone as an attribute's value passes the value itself, not a string:

```html
@foreach ($this->ready() as $order)
    <app-pickup-row key="{{ $order->code }}" order="{{ $order }}"></app-pickup-row>
@endforeach
```

[Child components and inputs](children.md) covers this in full.

## When templates compile

Every template is compiled when the first page uses a component, once all selectors are known, so that each template can expand the others' tags. A template that does not compile is an error then; in development a saved template is compiled again at once, and one that no longer compiles keeps its last working version until the mistake is fixed ([Development tools](development.md#hot-reload)).

See also: [Events and actions](events.md), [Static analysis](static-analysis.md).
