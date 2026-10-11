<?php

namespace Demo;

use Waypoint\Live\{Action, Component};

final class Counter extends Component
{
    private int $count = 0;

    public function mount(array $props): void
    {
        $start = $props['start'] ?? 0;
        $this->count = is_int($start) ? $start : 0;
    }

    #[Action]
    public function increment(): void
    {
        $this->count++;
    }

    #[Action]
    public function decrement(): void
    {
        $this->count--;
    }

    public function render(): string
    {
        return <<<HTML
            <button wp-live-click="decrement">−</button>
            <output>{$this->count}</output>
            <button wp-live-click="increment">+</button>
            HTML;
    }
}
