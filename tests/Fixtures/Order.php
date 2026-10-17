<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\Component;

/** Actions whose parameters take the browser's text as their types */
#[Component(
    selector: 'test-order',
    template: 'Order.Template.php'
)]
final class Order
{
    /** @var list<mixed> What the actions got, in order */
    public array $got = [];

    public function quantity(int $quantity): void
    {
        $this->got[] = $quantity;
    }

    public function discount(?float $discount): void
    {
        $this->got[] = $discount;
    }

    public function gift(bool $gift): void
    {
        $this->got[] = $gift;
    }

    public function size(Size $size): void
    {
        $this->got[] = $size;
    }

    public function sign(Signup $form): void
    {
        $this->got[] = $form;
    }
}
