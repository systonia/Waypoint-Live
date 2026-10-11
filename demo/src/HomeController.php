<?php

namespace Demo;

use Waypoint\Attributes\{Controller, Get, Inject};
use Waypoint\Http\View;

#[Controller]
final class HomeController
{
    // Makes CityStore reachable for discovery, so CitySearch can inject it
    #[Inject]
    private CityStore $cities;

    #[Get('/')]
    public function home(): View
    {
        return new View('Home', ['cityCount' => count($this->cities->all())]);
    }
}
