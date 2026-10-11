<?php

namespace Waypoint\Live;

use Closure;

/**
 * A live component: state in public or private properties, `#[Action]` methods the browser calls, render()
 * returning its HTML. Rendered once into the page by `$this->live(Class::class, $props)`, then created again
 * on the server when the browser connects, with the same props, and kept until the browser leaves. Services
 * come in through `#[Inject]` properties, as in a controller.
 */
abstract class Component
{
    /** Set while the browser is connected: sends a new render. Null while rendered into the page. */
    private ?Closure $push = null;

    /** @var list<\Tenma\Timer> */
    private array $timers = [];

    /**
     * Called once with the props from the page, before the first render. The props come back from the
     * browser when it connects, so treat them like query parameters: never as proof of who the user is.
     *
     * @param array<string, mixed> $props
     */
    public function mount(array $props): void
    {
    }

    abstract public function render(): string;

    /** Sends a new render to the browser outside an action (an action renders anyway). */
    protected function changed(): void
    {
        if ($this->push !== null) {
            ($this->push)();
        }
    }

    /** Runs $work every $seconds and renders afterwards, while the browser is connected; nothing in the page render. */
    protected function every(float $seconds, Closure $work): void
    {
        if ($this->push === null) {
            return;
        }
        $this->timers[] = \Tenma::setInterval(function () use ($work): void {
            $work();
            $this->changed();
        }, $seconds);
    }

    /** HTML-escapes a value for a template. */
    protected function e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES);
    }

    /** @internal Circuit: the browser is connected; $push sends a render. */
    public function connect(Closure $push): void
    {
        $this->push = $push;
    }

    /** @internal Circuit: the browser left; stops the timers. */
    public function disconnect(): void
    {
        foreach ($this->timers as $timer) {
            $timer->cancel();
        }
        $this->timers = [];
        $this->push = null;
    }
}
