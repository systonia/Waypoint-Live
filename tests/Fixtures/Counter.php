<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\{Component, Every, Input};

#[Component(selector: 'test-counter', template: 'Counter.Template.php')]
final class Counter
{
    #[Input]
    public int $count = 0;

    /** Public, but not an #[Input]: the page cannot set it. */
    public bool $admin = false;

    private string $label = '<b>';

    public function add(int $by): void
    {
        $this->count += $by;
    }

    /** Public, but not in the template: the browser must not reach it. */
    public function reset(): void
    {
        $this->count = 0;
    }

    #[Every(1.0)]
    private function tick(): void
    {
        $this->count++;
    }
}
