<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\Component;

#[Component(selector: 'test-list', template: 'ItemList.Template.php')]
final class ItemList
{
    /** @var list<string> */
    public array $items = ['a', 'b'];

    public string $picked = '';

    /** Called back by the children; not in the template, so the browser cannot call it directly. */
    public function pick(string $name): void
    {
        $this->picked = $name;
    }

    public function drop(): void
    {
        array_shift($this->items);
    }
}
