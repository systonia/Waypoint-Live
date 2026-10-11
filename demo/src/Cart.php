<?php

namespace Demo;

use Waypoint\Live\Scoped;

/** One cart per browser tab: the product list and the cart share it, another tab has its own. */
#[Scoped]
final class Cart
{
    /** @var array<string, int> product => quantity */
    public array $items = [];

    public function add(string $product): void
    {
        $this->items[$product] = ($this->items[$product] ?? 0) + 1;
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function count(): int
    {
        return array_sum($this->items);
    }
}
