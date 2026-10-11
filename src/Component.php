<?php

namespace Waypoint\Live;

use Attribute;

/**
 * Marks a class as a live component, Angular style:
 *
 *     #[Component(selector: 'app-counter', template: 'Counter.Template.php')]
 *     final class Counter { public int $count = 0; public function increment(): void { $this->count++; } }
 *
 * The template path is relative to the class's file. Views place the component with
 * `$this->live('app-counter', ['count' => 3])`; the browser only ever sees the selector.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Component
{
    public function __construct(public readonly string $selector, public readonly string $template)
    {
    }
}
