---
title: Development tools
description: Hot reload of templates and components, errors in the page and in the editor, checks while you type, and tracing and inspecting a tab.
order: 120
---

In development Live shows a lot more than in production: saved templates appear at once, errors show in the page with their source, the editor knows templates, and the browser's console can trace every action. All of it is on when Waypoint runs in development (`Environment::isDev()`, that is `APP_ENV=development`) under Tenma:

```bash
APP_ENV=development tenma --watch index.php
```

In production none of this happens: the browser only learns that a component failed (`data-live-error`), and the details go to the log.

## Hot reload

- **A saved template** renders at once in every open tab, and the components keep their state. Live looks for changed templates four times a second, by content, so a save without a change does nothing.
- **A template that no longer compiles** keeps its last working version. The mistake shows in the page and in the editor until the template is saved without it.
- **A saved PHP file** restarts the workers (`tenma --watch`). If it was a component's class, the open pages keep their components and these get their state back: the properties with plain values (numbers, strings, booleans, arrays of them), children by selector and key. A property that the new code no longer has, or whose type no longer takes the old value, starts over, and the log says so. Services, objects and closures start over too. This needs a Tenma with `Tenma::reloadedBy()`; without it, the pages load again.
- **Any other saved file** (a service, a view, the layout) loads the open pages again.

## Checks

Once the app has started, and after every saved template, Live checks every component and its template for mistakes that would otherwise only show at a click or a render:

- a `data-on-*` that names a method the component lacks, or one that is private or static (`Did you mean add()?`),
- a `data-on-click="add"` without `data-arg` for a method with a required parameter, which a click can never fill (a warning),
- a `data-on-<event>` for an event `live.js` does not listen to, such as `data-on-keyup` (a warning),
- PHP that would end the worker when the template renders: a `use` or a named function in `@code`, a `break` outside a loop ([Templates](templates.md#code)),
- an attribute of a child tag that is no input of the child, with the inputs it has,
- a component's tag that would stay as it is (single quotes, content inside),
- PHP that does not parse, a `//` comment inside `<?= ?>` or `{{ }}`,
- directives that do not close, or misspelt ones,
- a `#[Every]` method that cannot run as a timer,
- an `#[Input]` property that is not public, static or readonly.

The problems go to the terminal and, under a Tenma with `Tenma::diagnostics()`, to the editor, also for templates that are not open. A page that uses a tag close to a selector (`<app-conter>`) or an attribute close to an input gets a warning in the log, once per worker.

## Errors in the page

An error in an action, a timer or a render shows in the page, in a panel at the bottom that the page's own CSS cannot reach:

- the exception's class and message,
- the component's tag and what it was doing (`call "add"`, `mount`, `#[Every] tick`, `a broadcast`),
- for an error in a template, the template's file and line, with the lines around it,
- the stack, with the app's frames first and those of Live, packages and Tenma folded,
- links that open each file at its line in the editor.

The same error again counts up instead of stacking. The panel closes when the component renders again. A template that no longer compiles shows there too, until it is saved without the mistake.

The links open VS Code by default. For another editor, set [`LiveOptions::$editor`](../reference/Waypoint/Live/LiveOptions.md#editor), with `{file}` and `{line}` filled in:

```php
use Waypoint\Live\LiveOptions;

$app->configure(fn (LiveOptions $live) => $live->editor = 'vscodium://file/{file}:{line}');
// PhpStorm: 'phpstorm://open?file={file}&line={line}'
```

Under a Tenma with `Tenma::diagnostics()` and `tenma lsp`, the same errors show in the editor at their line, until the component renders again or the template compiles.

## In the editor

The package brings a provider for `tenma lsp`, Tenma's language server, which runs next to the editor's PHP extension. In VS Code and VSCodium the Tenma extension starts it in trusted workspaces. It finds the components below the project's `autoload` and `autoload-dev` directories without running the app, and in a template it gives:

- `$this->` completed with the component's properties and methods, private ones included; hover, and go to definition into the class,
- `data-on-click="` completed with the methods the browser may call; go to definition to the method,
- `<app-` completed with the selectors, and the attributes of a child tag with its `#[Input]` properties; go to definition from a tag to its class,
- the variable of a loop over `$this->items` completed with the members of the element type that the property's `@var list<Item>` (or `Item[]`, `array<int, Item>`) names,
- colours for directives and the PHP inside them, which the editor's PHP grammar sees as text,
- while typing: the problems listed under [Checks](#checks), each at its line.

A changed component class is seen once it is saved.

## Tracing actions

In the browser's console, turn tracing on for the site:

```js
localStorage.liveDebug = '1'
```

or add `?live-debug` to the page's URL. Every action, timer, broadcast and reconnect then logs a group: what caused it, its time on the server and the round trip, and per component the render time, the size of what was sent (or that nothing changed) and the time the browser took to patch it.

```
live call "add" → 3.1 ms server · 9 ms round trip · 1 of 2 sent · 0.4 KB
  c0     <app-product-list> rendered 0.8 ms  unchanged
  c1     <app-cart-badge> rendered 0.3 ms  sent 0.1 KB, patched 0.2 ms
```

## Inspecting a component

`live.inspect('c0')` in the console prints what a component holds on the server: its selector and class, its inputs, its state, its children, the number of its timers, and the classes of the tab's scoped services (not their content). Top-level components are `c0`, `c1`, … in page order; a child's ID is on its tag as `data-live-id` (`c0.1`).

## Warnings

The console and the log warn when

- an action takes more than 50 ms (every change renders the whole tab),
- one message is larger than 100 KB (a list without paging?),
- a tab has more than 30 components (at most 50 top-level ones are allowed),
- timers send more than 10 renders in one second.

See also: [Static analysis](static-analysis.md), [`LiveOptions`](../reference/Waypoint/Live/LiveOptions.md).
