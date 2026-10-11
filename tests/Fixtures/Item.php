<?php

namespace Waypoint\Live\Tests\Fixtures;

use Closure;
use Waypoint\Live\{Component, Every, Input};

#[Component(selector: 'test-item', template: 'Item.Template.php')]
final class Item
{
    #[Input]
    public string $name = '';

    #[Input]
    public ?Closure $pick = null;

    public int $clicks = 0;

    public function choose(): void
    {
        $this->clicks++;
        ($this->pick)($this->name);
    }

    #[Every(1.0)]
    private function tick(): void
    {
    }
}
