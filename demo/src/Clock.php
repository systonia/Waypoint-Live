<?php

namespace Demo;

use Waypoint\Live\Component;

/** Pushed from the server every second: no event from the browser involved. */
final class Clock extends Component
{
    private string $time;

    public function mount(array $props): void
    {
        $this->time = date('H:i:s');
        $this->every(1.0, function (): void {
            $this->time = date('H:i:s');
        });
    }

    public function render(): string
    {
        return "<time>{$this->time}</time>";
    }
}
