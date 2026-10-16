---
title: Static analysis
description: Check components and templates without running the app with vendor/bin/live-check, and keep PHPStan from flagging members only templates use.
order: 130
---

Templates are PHP, but PHPStan does not read them on its own, and mistakes in them (a misspelt property, a method with the wrong arguments, a `data-on-click` that names nothing) would only show when a page renders or a button is clicked. Live closes that gap twice: `vendor/bin/live-check` analyses every template, and a PHPStan extension tells PHPStan which members the templates use.

## live-check

```bash
composer require --dev phpstan/phpstan
vendor/bin/live-check
```

It finds every class with `#[Component]` below the project's `autoload` and `autoload-dev` directories (PSR-4 and classmap), without running the app, and reports:

1. what the app checks in development ([Development tools](development.md#checks)): actions the component lacks, attributes that are no input of the child, tags that stay as they are, PHP that does not parse, directives that do not close, timers that cannot run, inputs that cannot be set;
2. PHPStan's findings in every template, read as the PHP it becomes with `$this` as its component: an undefined property or method, wrong argument types, a typo in the member of a loop variable whose type the property's `@var` names.

Each problem is reported at the template's own line:

```
$ vendor/bin/live-check
src/ProductList.Template.php:2: error: Call to an undefined method App\Catalog::prodcts().
live-check: 1 errors, 0 warnings
```

```
$ vendor/bin/live-check
src/ProductList.Template.php:4: error: data-on-click="ad($product)": ProductList has no method ad(). Did you mean add()?
live-check: 1 errors, 0 warnings
```

A template with an error of the first kind is left out of the PHPStan run; it would only fail to parse.

| Exit code | Meaning |
|---|---|
| 0 | no errors (warnings may have been printed) |
| 1 | at least one error |
| 2 | live-check could not run, for example without PHPStan |

So the command fails a CI step as it is. An optional argument names the project's directory: `vendor/bin/live-check path/to/project`.

### Configuration

- PHPStan runs with the project's `phpstan.neon` (or `phpstan.neon.dist`): its level, its extensions, its bootstrap files. Without one, it runs at level 6.
- The generated files go to `var/live-check/`, one per component. Add `var/` to `.gitignore`.
- Types in templates come from the component's properties and methods. A property's `@var list<Task>` lets PHPStan check what a loop over it does with each `$task`.

### In the editor while saving

PHPStan is too slow to run on every keystroke, but a Tenma build hook can run it whenever a file below `src/` is saved and send the findings to the editor. In the project's `tenma.dev.php`:

```php
<?php

return [
    new Tenma\Hook(
        name: 'live-check',
        inputs: ['src/**'],
        run: fn () => Waypoint\Live\Check::publish(__DIR__),
    ),
];
```

[`Check::publish()`](../reference/Waypoint/Live/Check.md#publish) runs PHPStan on the templates and hands the findings to `Tenma::diagnostics()`, which `tenma lsp` shows at their line. The checks of the first kind already reach the editor while typing, through the language server ([Development tools](development.md#in-the-editor)).

## The PHPStan extension

To PHPStan, a private property that only a component's template reads is never read, and a private method that only the template or a timer calls is unused. The package's PHPStan extension makes these count as used:

- every private property and method of a class with `#[Component]`,
- every method with `#[Every]`.

With `phpstan/extension-installer` the extension is included on its own. Without it, add it to the project's `phpstan.neon`:

```neon
includes:
    - vendor/systonia/waypoint-live/extension.neon
```

## In CI

```bash
vendor/bin/phpstan analyse
vendor/bin/live-check
vendor/bin/phpunit
```

PHPStan checks the classes, `live-check` the templates, and the PHPUnit tests with [`Testing\Tab`](testing.md) prove that the templates wire the right methods to the right elements.

See also: [`Check`](../reference/Waypoint/Live/Check.md), [Development tools](development.md).
