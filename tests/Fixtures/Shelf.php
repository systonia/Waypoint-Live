<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\Component;

/** Its template uses directives. */
#[Component(
    selector: 'test-shelf',
    template: 'Shelf.Template.php'
)]
final class Shelf
{
    /** @var list<string> */
    public array $books = ['Dune', '<Emma>'];

    public bool $open = true;

    public function pick(string $title): void
    {
    }
}
