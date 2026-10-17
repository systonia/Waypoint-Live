<?php

namespace Waypoint\Live;

/**
 * Markup a template prints as it is: the same as `Waypoint\Html`, which templates and views print as it is too.
 * New code uses `Waypoint\Html`; this name stays for templates written before it. Only for HTML that was built
 * safely, such as another template's output; anything from a user stays a string and is escaped.
 */
final readonly class Html extends \Waypoint\Html
{
}
