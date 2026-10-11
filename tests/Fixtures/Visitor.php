<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Attributes\Inject;
use Waypoint\Live\{Component, Connection};

#[Component(
    selector: 'test-visitor',
    template: 'Visitor.Template.php'
)]
final class Visitor
{
    #[Inject]
    public Connection $connection;

    public function leave(string $to): void
    {
        $this->connection->navigate($to);
    }
}
