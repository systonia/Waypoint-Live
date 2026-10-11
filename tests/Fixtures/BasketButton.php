<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

#[Component(
    selector: 'test-basket-button',
    template: 'BasketButton.Template.php'
)]
final class BasketButton
{
    #[Inject]
    public Basket $basket;

    public function add(): void
    {
        $this->basket->items++;
    }
}
