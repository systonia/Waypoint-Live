<?php

namespace Demo;

use Closure;
use Waypoint\Live\{Component, Input};

/** One city in the search: tells its parent about a click through the closure it was given. */
#[Component(selector: 'app-city-row', template: 'CityRow.Template.php')]
final class CityRow
{
    #[Input]
    public string $city = '';

    #[Input]
    public bool $selected = false;

    #[Input]
    public ?Closure $pick = null;

    public function choose(): void
    {
        ($this->pick)($this->city);
    }
}
