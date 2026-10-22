<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\Component;

/** A template with what only PHPStan finds: Waypoint\View\Check::phpstan() */
#[Component]
final class Sloppy
{
    private int $count = 0;

    /** @var list<Item> */
    public array $items = [];
}
