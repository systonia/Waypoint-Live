<?php

namespace Demo;

use Waypoint\Live\{Component, Input};

#[Component]
final class Counter
{
    #[Input]
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function decrement(): void
    {
        $this->count--;
    }
}
