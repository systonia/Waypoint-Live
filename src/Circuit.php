<?php

namespace Waypoint\Live;

use Closure;
use InvalidArgumentException;
use ReflectionMethod;
use Throwable;

/**
 * One browser tab's components on the server, for as long as its WebSocket is open. Messages from the browser
 * (JSON): `{"t":"mount","id":"c0","c":"App\\Counter","p":{...}}` creates a component, `{"t":"call","id":"c0",
 * "m":"increment","a":[...]}` calls one of its #[Action] methods. Every change goes back as `{"id","html"}`,
 * a failure as `{"id","error":true}` without details; the circuit keeps running either way.
 */
final class Circuit
{
    // ponytail: fixed limit per tab, an option once an app needs more
    private const MAX_COMPONENTS = 50;

    /** @var array<string, Component> */
    private array $components = [];

    /**
     * @param Closure(string): Component $create Creates an allowed component class, services injected.
     * @param Closure(string): void $send Writes one message to the browser.
     * @param Closure(Throwable): void $report Logs a failed message.
     */
    public function __construct(private Closure $create, private Closure $send, private Closure $report)
    {
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

    /** The browser left: every component stops its timers. */
    public function close(): void
    {
        foreach ($this->components as $component) {
            $component->disconnect();
        }
        $this->components = [];
    }

    private function mount(string $id, mixed $class, mixed $props): void
    {
        if (isset($this->components[$id]) || count($this->components) >= self::MAX_COMPONENTS) {
            throw new InvalidArgumentException("Cannot mount component '$id'");
        }
        if (!is_string($class) || !is_array($props) || ($props !== [] && array_is_list($props))) {
            throw new InvalidArgumentException('mount needs a class name and props as an object');
        }
        /** @var array<string, mixed> $props */
        $component = ($this->create)($class);
        $component->connect(fn () => $this->render($id));
        $this->components[$id] = $component;
        $component->mount($props);
        $this->render($id);
    }

    private function call(string $id, mixed $method, mixed $args): void
    {
        $component = $this->components[$id] ?? throw new InvalidArgumentException("No component '$id'");
        if (!is_string($method) || !method_exists($component, $method) || !is_array($args) || !array_is_list($args)) {
            throw new InvalidArgumentException('call needs an action name and a list of arguments');
        }
        $reflection = new ReflectionMethod($component, $method);
        // Only what the component opened up: never mount(), render() or any other public method
        if (!$reflection->isPublic() || $reflection->isStatic() || $reflection->getAttributes(Action::class) === []) {
            throw new InvalidArgumentException("'$method' is not an #[Action]");
        }
        $reflection->invokeArgs($component, $args);
        $this->render($id);
    }

    private function render(string $id): void
    {
        $component = $this->components[$id] ?? null;
        if ($component !== null) {
            ($this->send)(json_encode(['id' => $id, 'html' => $component->render()], JSON_THROW_ON_ERROR));
        }
    }
}
