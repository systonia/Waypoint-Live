<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\Component;

/** A float no JSON can hold */
#[Component]
final class Gauge
{
    public float $value = NAN;
}
