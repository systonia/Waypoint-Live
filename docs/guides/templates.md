---
title: Templates
description: Write a component's template with escaped output, comments, @if and @foreach, plain PHP, and Html for markup you built yourself.
order: 20
---

A template is a PHP file rendered with `$this` as the component, private properties and methods included. On top of PHP it knows a few directives: `{{ }}` to print, `{{/* */}}` for comments, and `@if` and `@foreach` blocks. Directives and plain PHP mix freely; each directive becomes PHP on the same line, so error messages and the editor point at the template's own lines.

## A template with everything

A product card. The class:

```php
<?php
// src/ProductCard.php

namespace App;

use Waypoint\Live\{Component, Html, Input};

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
- A value of type [`Html`](../reference/Waypoint/Live/Html.md) prints as it is (below).
- `<?php echo $value ?>` prints **without** escaping. Use it only for markup that is already safe; `Html` says the same more clearly.
- A `//` comment inside `<?= ?>` or `{{ }}` would also comment out what the compiler adds behind it: use `/* */` there. The template does not compile otherwise, and the editor and the checks report it.

### Html: markup you built yourself

Whether a value prints raw depends on the value, never on how the template prints it. A method that returns [`Html`](../reference/Waypoint/Live/Html.md) is printed as it is, by `{{ }}` and `<?= ?>` alike:

```php
private function description(): Html
{
    $paragraphs = array_map(static fn (string $line) => '<p>' . htmlspecialchars($line) . '</p>', explode("\n", $this->description));
    return new Html(implode('', $paragraphs));
}
```

`Html` is a promise that the markup is safe. Build it from escaped parts, as above, or from another template's output; anything that came from a user stays a string, so that it is escaped.

## Comments

`{{/* ... */}}` is a comment and leaves nothing in the HTML. It may span lines:

```html
{{/*
    The badge is hidden until the design is done:
    <span class="badge">{{ $this->badge }}</span>
*/}}
```

An HTML comment `<!-- -->` stays in the HTML the browser gets, as in any page. Neither kind of comment makes a method callable: a `data-on-click` inside a comment is ignored ([Events and actions](events.md#what-the-browser-can-call)).

## Directives

| Directive | PHP it becomes |
|---|---|
| `@if (cond)` | `<?php if (cond): ?>` |
| `@elseif (cond)` | `<?php elseif (cond): ?>` |
| `@else` | `<?php else: ?>` |
| `@endif` | `<?php endif ?>` |
| `@foreach (expr)` | `<?php foreach (expr): ?>` |
| `@endforeach` | `<?php endforeach ?>` |
| `@@` | a literal `@` |

The condition may span lines and contain parentheses and strings: `@if (in_array('a)', $this->tags, true))` works.

A directive starts a line or follows whitespace or `>`. So `shop@example.com`, `@media` in a `<style>` block and attributes like `@click` stay text. Use `@@` where an `@` that should stay text follows a space, as in `@@{{ $tag }}`.

Directives work inside a tag, for attributes that come and go:

```html
<input type="checkbox" data-on-change="toggle($task)" @if ($task->done) checked @endif>
<button @if ($this->saving) disabled @endif data-on-click="save">Save</button>
```

Inside `<?php ?>` nothing is a directive.

### Mistakes

A block that does not close, an `@endif` that closes an `@foreach`, a `{{` without `}}` or an empty `{{ }}` keeps the template from compiling, with the line:

```
Template '/srv/shop/src/ProductCard.Template.php', line 4: @if has no @endif
```

So does a `/*` without its `*/` inside `{{ }}` or a directive's parentheses, which would comment out the template up to the next `*/`. A misspelt directive such as `@forech` gets a warning (`Did you mean @foreach?`) and stays text. In the editor these show while you type ([Development tools](development.md)).

## Plain PHP

A template is PHP, and PHP's own syntax works as in any view. These are the same:

```html
@foreach ($this->visible() as $task)
    <li>{{ $task->title }}</li>
@endforeach

<?php foreach ($this->visible() as $task): ?>
    <li><?= $task->title ?></li>
<?php endforeach ?>
```

A local variable for the rest of the template is plain PHP: `<?php $count = count($this->tags) ?>`. Keep logic in the class, though: a private method such as `visible()` is easier to test and to read than a block of PHP in the HTML.

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

See also: [`Html`](../reference/Waypoint/Live/Html.md), [Events and actions](events.md), [Static analysis](static-analysis.md).
