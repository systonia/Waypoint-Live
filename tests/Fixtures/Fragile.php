<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Attributes\Inject;
use Waypoint\Live\{Component, Input};

#[Component(
    selector: 'test-fragile',
    template: 'Fragile.Template.php'
)]
final class Fragile
{
    #[Input]
    public bool $broken = false;

    #[Inject]
    public Label $label;

    public function toggle(): void
    {
        $this->broken = !$this->broken;
    }
}
