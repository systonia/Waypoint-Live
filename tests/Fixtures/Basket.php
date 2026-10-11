<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\Scoped;

#[Scoped]
final class Basket
{
    public int $items = 0;
}
