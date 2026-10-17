<?php

namespace Waypoint\Live;

/**
 * The same as `Waypoint\classes()`, which every template and view calls without an import; this name stays for
 * code written before it.
 *
 * @param string|array<array-key, mixed> ...$parts
 */
function classes(string|array ...$parts): string
{
    return \Waypoint\classes(...$parts);
}
