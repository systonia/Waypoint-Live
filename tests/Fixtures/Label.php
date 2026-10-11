<?php

namespace Waypoint\Live\Tests\Fixtures;

/** An app service the container can't build on its own. */
final class Label
{
    public function __construct(public string $text)
    {
    }
}
