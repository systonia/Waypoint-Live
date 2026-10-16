<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Attributes\Inject;

/** An app-wide service that injects a #[Scoped] one: refused. */
final class Tally
{
    #[Inject]
    public Basket $basket;
}
