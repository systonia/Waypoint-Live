<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\{Component, Html as Markup};
use Waypoint\Live\Tests\Fixtures\Sub\Phase;
use function strtoupper as shout;

/** Its template names classes and functions as this file does. */
#[Component(
    selector: 'test-board',
    template: 'Board.Template.php'
)]
final class Board
{
    use Sub\Marks;

    public Phase $phase = Phase::Open;

    public bool $done = false;

    public function toggle(): void
    {
        $this->done = !$this->done;
    }
}
