<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\Component;

/** Its template doesn't exist. */
#[Component(selector: 'test-orphan', template: 'Missing.Template.php')]
final class Orphan
{
}
