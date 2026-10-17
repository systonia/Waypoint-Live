---
title: "Check"
description: "`vendor/bin/live-check`: the components of a project checked without running the app."
order: 100
generated: true
---

`Waypoint\Live\Check` · final class · namespace [`Waypoint\Live`](index.md)

```php
final class Check
```

`vendor/bin/live-check`: the components of a project checked without running the app. Problems finds what the
app checks in development, and PHPStan reads every template as the PHP it becomes, with `$this` as its
component: an undefined property, a method with the wrong arguments, a typo in a loop variable's member, at the
template's line. In a `tenma.dev.php` hook, publish() shows PHPStan's findings in the editor:

    new Tenma\Hook(
        name: 'live-check',
        inputs: ['src/**'],
        run: fn () => Waypoint\Live\Check::publish(__DIR__),
    ),

## Methods

### problems()

```php
public static function problems(string $root): array
```

What Problems finds in all components of the project at $root.

**Parameters**

- `$root` `string`

**Returns** `list<array{file: string, line: int, message: string, severity: 'error'|'warning'}>`

### phpstan()

```php
public static function phpstan(string $root): array
```

What PHPStan finds in the templates of the project at $root, at their lines. Templates with an error Problems
finds are left out: they would only fail to parse. PHPStan runs with the project's phpstan.neon (its level,
its extensions) if there is one, otherwise at level 6.

**Parameters**

- `$root` `string`

**Returns** `list<array{file: string, line: int, message: string, severity: 'error'}>`

### publish()

```php
public static function publish(string $root): void
```

For a tenma.dev.php hook: PHPStan's findings in the templates go to the editor, through Tenma::diagnostics().

**Parameters**

- `$root` `string`
