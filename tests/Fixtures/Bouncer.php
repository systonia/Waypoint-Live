<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Attributes\Inject;
use Waypoint\Live\{Component, Connection};

/** Navigates while it renders: allowed only from actions. */
#[Component(
    selector: 'test-bouncer',
    template: 'Bouncer.Template.php'
)]
final class Bouncer
{
    #[Inject]
    public Connection $connection;
}
