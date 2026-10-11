<?php

namespace Demo;

use Waypoint\Live\Component;

/** Child counters with their own state: adding or removing one leaves the others' counts alone. */
#[Component(selector: 'app-counter-list', template: 'CounterList.Template.php')]
final class CounterList
{
    /** @var list<int> */
    private array $counters = [1, 2];

    private int $next = 3;

    public function add(): void
    {
        $this->counters[] = $this->next++;
    }

    public function removeFirst(): void
    {
        array_shift($this->counters);
    }
}
