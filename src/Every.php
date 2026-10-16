<?php

namespace Waypoint\Live;

use Attribute;

/** Runs the method every $seconds while the browser is connected and pushes a new render afterwards. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Every
{
    /** @param float $seconds Time between two runs, more than 0; the first run comes $seconds after the component is created */
    public function __construct(public readonly float $seconds)
    {
    }
}
