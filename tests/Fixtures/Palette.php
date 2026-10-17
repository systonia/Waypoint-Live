<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\Component;

#[Component(
    selector: 'test-palette',
    template: 'Palette.Template.php'
)]
final class Palette
{
    public string $picked = 'none';

    /** @return list<Shade> Built again by every render, as a repository returns them */
    private function shades(): array
    {
        return [new Shade('red', Mood::Calm), new Shade('blue', Mood::Calm)];
    }

    public function pick(Shade $shade): void
    {
        $this->picked = $shade->name;
    }

    public function feel(Mood $mood): void
    {
        $this->picked = $mood->name;
    }
}
