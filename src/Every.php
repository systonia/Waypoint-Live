<?php

namespace Waypoint\Live;

use Attribute;

/** Runs the method every $seconds while the browser is connected and pushes a new render afterwards. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Every
{
    public function __construct(public readonly float $seconds)
    {
    }
}
