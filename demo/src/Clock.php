<?php

namespace Demo;

use Waypoint\Live\{Component, Every};

/** Pushed from the server every second: no event from the browser involved. */
#[Component]
final class Clock
{
    private string $time;

    public function __construct()
    {
        $this->tick();
    }

    #[Every(1.0)]
    private function tick(): void
    {
        $this->time = date('H:i:s');
    }
}
