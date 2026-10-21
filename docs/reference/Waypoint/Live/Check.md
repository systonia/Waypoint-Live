---
title: "Check"
description: "`vendor/bin/live-check`, the same as `vendor/bin/waypoint-check`: the components of a project, and its views, checked without running the app."
order: 100
generated: true
---

`Waypoint\Live\Check` · final class · namespace [`Waypoint\Live`](index.md)

```php
final class Check
```

`vendor/bin/live-check`, the same as `vendor/bin/waypoint-check`: the components of a project, and its views,
checked without running the app. Problems finds what the app checks in development, and PHPStan reads every
template as the PHP it becomes, with `$this` as its component: an undefined property, a method with the wrong
arguments, a typo in a loop variable's member, at the template's line. Templates take part in Waypoint's check
through TemplateChecks; this class keeps the names of before. In a `tenma.dev.php` hook, publish() shows
PHPStan's findings in the editor:

    new Development\Hook(
        name: 'waypoint-check',
        inputs: ['src/**', 'views/**'],
        run: fn () => Waypoint\View\Check::publish(__DIR__),
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

What PHPStan finds in the templates and views of the project at $root, at their lines: the same as
`Waypoint\View\Check::phpstan()`, which this name stays for.

**Parameters**

- `$root` `string`

**Returns** `list<array{file: string, line: int, message: string, severity: 'error'}>`

### publish()

```php
public static function publish(string $root): void
```

For a tenma.dev.php hook: the same as `Waypoint\View\Check::publish()`, which this name stays for.

**Parameters**

- `$root` `string`
