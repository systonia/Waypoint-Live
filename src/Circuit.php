<?php

namespace Waypoint\Live;

use Closure;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionObject;
use Throwable;

/**
 * One browser tab's components on the server, for as long as its WebSocket is open. Messages from the browser
 * (JSON): `{"t":"mount","id":"c0","c":"app-counter","p":{...}}` creates a component with its inputs,
 * `{"t":"call","id":"c0","m":"increment","a":[...]}` calls a method its template wires up. Every change goes
 * back as `{"id","html"}`, a failure as `{"id","error":true}` without details; the circuit keeps running.
 */
final class Circuit
{
    // ponytail: fixed limit per tab, an option once an app needs more
    private const MAX_COMPONENTS = 50;

    /** @var array<string, array{object, Template}> */
    private array $components = [];

    /** @var array<string, list<Closure(): void>> Each component's timers, as functions that cancel them. */
    private array $timers = [];

    /** @var Closure(float, Closure(): void): (Closure(): void) */
    private Closure $every;

    /**
     * @param Closure(string, array<string, mixed>): array{object, Template} $create Creates a listed component with its inputs set.
     * @param Closure(string): void $send Writes one message to the browser.
     * @param Closure(Throwable): void $report Logs a failure.
     * @param (Closure(float, Closure(): void): (Closure(): void))|null $every Starts a timer and returns its cancel; Tenma's setInterval by default.
     */
    public function __construct(private Closure $create, private Closure $send, private Closure $report, ?Closure $every = null)
    {
        $this->every = $every ?? static fn (float $seconds, Closure $work): Closure => \Tenma::setInterval($work, $seconds)->cancel(...);
    }

    public function handle(string $message): void
    {
        $msg = json_decode($message, true);
        $id = is_array($msg) ? ($msg['id'] ?? null) : null;
        if (!is_array($msg) || !is_string($id)) {
            return;
        }
        try {
            match ($msg['t'] ?? null) {
                'mount' => $this->mount($id, $msg['c'] ?? null, $msg['p'] ?? []),
                'call' => $this->call($id, $msg['m'] ?? null, $msg['a'] ?? []),
                default => throw new InvalidArgumentException('Unknown message type'),
            };
        } catch (Throwable $e) {
            ($this->report)($e);
            ($this->send)(json_encode(['id' => $id, 'error' => true], JSON_THROW_ON_ERROR));
        }
    }

    /** The browser left: every timer stops. */
    public function close(): void
    {
        foreach ($this->timers as $cancels) {
            foreach ($cancels as $cancel) {
                $cancel();
            }
        }
        $this->timers = [];
        $this->components = [];
    }

    private function mount(string $id, mixed $selector, mixed $inputs): void
    {
        if (isset($this->components[$id]) || count($this->components) >= self::MAX_COMPONENTS) {
            throw new InvalidArgumentException("Cannot mount component '$id'");
        }
        if (!is_string($selector) || !is_array($inputs) || ($inputs !== [] && array_is_list($inputs))) {
            throw new InvalidArgumentException('mount needs a selector and inputs as an object');
        }
        /** @var array<string, mixed> $inputs */
        [$component] = $this->components[$id] = ($this->create)($selector, $inputs);
        $this->render($id);
        foreach ((new ReflectionObject($component))->getMethods() as $method) {
            foreach ($method->getAttributes(Every::class) as $attribute) {
                $this->timers[$id][] = ($this->every)($attribute->newInstance()->seconds, function () use ($id, $component, $method): void {
                    try {
                        $method->invoke($component);
                        $this->render($id);
                    } catch (Throwable $e) {
                        ($this->report)($e);
                    }
                });
            }
        }
    }

    private function call(string $id, mixed $method, mixed $args): void
    {
        [$component, $template] = $this->components[$id] ?? throw new InvalidArgumentException("No component '$id'");
        // Only what the template wires up: a public method elsewhere in the class stays out of reach
        if (!is_string($method) || !in_array($method, $template->actions, true) || !is_array($args) || !array_is_list($args)) {
            throw new InvalidArgumentException('call needs a method the template names and a list of arguments');
        }
        $reflection = new ReflectionMethod($component, $method);
        if (!$reflection->isPublic() || $reflection->isStatic()) {
            throw new InvalidArgumentException("'$method' is not a public method");
        }
        $reflection->invokeArgs($component, $args);
        $this->render($id);
    }

    private function render(string $id): void
    {
        [$component, $template] = $this->components[$id] ?? [null, null];
        if ($component !== null && $template !== null) {
            ($this->send)(json_encode(['id' => $id, 'html' => $template->render($component)], JSON_THROW_ON_ERROR));
        }
    }
}
