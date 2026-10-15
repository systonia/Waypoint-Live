<?php

namespace Demo;

use Waypoint\Attributes\Inject;
use Waypoint\Live\Component;

#[Component]
final class Products
{
    #[Inject]
    private Cart $cart;

    /** @var list<string> */
    private array $products = ['Kaffee', 'Tee', 'Kakao'];

    public function add(string $product): void
    {
        $this->cart->add($product);
    }
}
