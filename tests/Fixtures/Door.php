<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Attributes\Inject;
use Waypoint\Live\{Component, Connection};

/** Sends the browser elsewhere, or fails */
#[Component]
final class Door
{
    #[Inject]
    public Connection $connection;

    public function leave(): void
    {
        $this->connection->navigate('/elsewhere');
    }

    public function jam(): void
    {
        throw new \RuntimeException('the door is stuck');
    }
}
