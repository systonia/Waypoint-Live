<?php

namespace Demo;

use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

#[Component(selector: 'app-cart', template: 'CartSummary.Template.php')]
final class CartSummary
{
    #[Inject]
    private Cart $cart;

    public function clear(): void
    {
        $this->cart->clear();
    }
}
