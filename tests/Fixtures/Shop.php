<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\Component;

/** Actions with objects as arguments: data-on-click="remove($product)". */
#[Component(
    selector: 'test-shop',
    template: 'Shop.Template.php'
)]
final class Shop
{
    /** @var list<Product> */
    public array $products;

    public function __construct()
    {
        $this->products = [new Product('tea'), new Product('coffee')];
    }

    public function remove(Product $product): void
    {
        $this->products = array_values(array_filter($this->products, static fn (Product $p) => $p !== $product));
    }

    public function rename(Product $product, string $name): void
    {
        $product->name = $name;
    }

    public function reverse(): void
    {
        $this->products = array_reverse($this->products);
    }
}
