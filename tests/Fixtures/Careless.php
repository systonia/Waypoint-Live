<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\{Component, Every, Input};

/** Mistakes Problems::inComponent() finds */
#[Component]
final class Careless
{
    #[Input]
    private int $hidden = 0;

    #[Every(1.0)]
    public function tick(int $n): void
    {
    }

    #[Every(0.0)]
    public function never(): void
    {
    }
}
