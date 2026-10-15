<?php

namespace Demo;

use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

#[Component(
    selector: 'app-cart'
)]
final class CartSummary
{
    #[Inject]
    private Cart $cart;

    public function clear(): void
    {
        $this->cart->clear();
    }
}
