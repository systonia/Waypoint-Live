<?php

namespace Waypoint\Live;

use Attribute;

/** Marks a public component method the browser may call: `wp-live-click="increment"` calls `#[Action] increment()`. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Action
{
}
