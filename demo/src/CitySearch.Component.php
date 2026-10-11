<?php

namespace Demo;

use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

#[Component(
    selector: 'app-city-search',
    template: 'CitySearch.Template.php'
)]
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

    public function select(string $city): void
    {
        $this->selected = $city;
    }
}
