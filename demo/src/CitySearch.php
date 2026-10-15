<?php

namespace Demo;

use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

#[Component]
final class CitySearch
{
    #[Inject]
    private CityStore $cities;

    private string $query = '';
    private ?string $selected = null;

    public function search(string $query): void
    {
        $this->query = $query;
    }

    /** Called back by the rows; not in the template, so the browser cannot call it directly. */
    public function select(string $city): void
    {
        $this->selected = $city;
    }
}
