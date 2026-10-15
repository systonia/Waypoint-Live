<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\{Component, Every};

/** A timer that fails on its second tick, after it counted */
#[Component]
final class Ticker
{
    public int $count = 0;

    #[Every(1.0)]
    public function tick(): void
    {
        $this->count++;
        if ($this->count === 2) {
            throw new \RuntimeException('second tick');
        }
    }
}
