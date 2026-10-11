<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

#[Component(
    selector: 'test-basket-badge',
    template: 'BasketBadge.Template.php'
)]
final class BasketBadge
{
    #[Inject]
    public Basket $basket;
}
