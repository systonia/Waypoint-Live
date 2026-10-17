<?php

namespace Waypoint\Live;

use Waypoint\View\Compiler;

/**
 * @internal The template syntax of Waypoint's views (Compiler: `{{ }}`, `@if`, `@foreach`, `@code`, `@use`,
 * `@class.`, `@@`) in a component's template, which differs only where a component does:
 *
 * - no view directives (`@model`, `@section`, `@yield`): a template has `$this` and no layout;
 * - no `use` or `namespace`: a template has those of its component's class file, and `@use` adds an import;
 * - no `@class.` on a child component's tag: a child takes inputs.
 */
final class Directives extends Compiler
{
    protected const array VIEW = [];

    protected const string NOUN = 'template';

    /** @param list<string> $selectors The app's components: a @class. on their tags is an error */
    public function __construct(string $source, private readonly array $selectors = [])
    {
        parent::__construct($source);
    }

    protected function refusal(string $kind): string
    {
        return match ($kind) {
            'use' => 'use in a template: it has the imports of its component\'s class file, and @use (App\\Status) adds one',
            'namespace' => 'namespace in a template: it has the namespace of its component\'s class',
            'function' => 'A named function in a template is declared again on the next render, which PHP refuses: use a closure or a method of the component',
            default => '$this is the component: a template cannot assign or unset it',
        };
    }

    protected function refusesClasses(string $tag, string $class): ?string
    {
        return in_array($tag, $this->selectors, true)
            ? "<$tag @class.$class>: a child component takes inputs, not classes. Put the class on an element inside its template, or pass an input"
            : null;
    }
}
