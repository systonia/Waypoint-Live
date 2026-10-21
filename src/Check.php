<?php

namespace Waypoint\Live;

use Closure;

/**
 * `vendor/bin/live-check`, the same as `vendor/bin/waypoint-check`: the components of a project, and its views,
 * checked without running the app. Problems finds what the app checks in development, and PHPStan reads every
 * template as the PHP it becomes, with `$this` as its component: an undefined property, a method with the wrong
 * arguments, a typo in a loop variable's member, at the template's line. Templates take part in Waypoint's check
 * through TemplateChecks; this class keeps the names of before. In a `tenma.dev.php` hook, publish() shows
 * PHPStan's findings in the editor:
 *
 *     new Development\Hook(
 *         name: 'waypoint-check',
 *         inputs: ['src/**', 'views/**'],
 *         run: fn () => Waypoint\View\Check::publish(__DIR__),
 *     ),
 */
final class Check
{
    /**
     * @internal What PHPStan reads in a generated file: $template runs with `$this` as a $class, private members included,
     * as a template does.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param Closure(Closure(string, array<string, mixed>): string, Closure(string, list<mixed>): string): void $template
     * @param-closure-this T $template
     */
    public static function bind(string $class, Closure $template): void
    {
    }

    /**
     * What Problems finds in all components of the project at $root.
     *
     * @return list<array{file: string, line: int, message: string, severity: 'error'|'warning'}>
     */
    public static function problems(string $root): array
    {
        return (new TemplateChecks($root))->problems();
    }

    /**
     * What PHPStan finds in the templates and views of the project at $root, at their lines: the same as
     * `Waypoint\View\Check::phpstan()`, which this name stays for.
     *
     * @return list<array{file: string, line: int, message: string, severity: 'error'}>
     */
    public static function phpstan(string $root): array
    {
        return \Waypoint\View\Check::phpstan($root);
    }

    /** For a tenma.dev.php hook: the same as `Waypoint\View\Check::publish()`, which this name stays for. */
    public static function publish(string $root): void
    {
        \Waypoint\View\Check::publish($root);
    }
}
