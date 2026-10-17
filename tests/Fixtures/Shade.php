<?php

namespace Waypoint\Live\Tests\Fixtures;

/** A value that cannot change: the same shade built again by the next render is the same. */
final readonly class Shade
{
    public function __construct(public string $name, public Mood $mood)
    {
    }
}
