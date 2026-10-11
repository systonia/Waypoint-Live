<?php

namespace Waypoint\Live;

use Closure;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use ReflectionObject;
use Throwable;

/**
 * One browser tab's components on the server, for as long as its WebSocket is open. Messages from the browser
 * (JSON): `{"t":"mount","id":"c0","c":"app-counter","p":{...}}` creates a top-level component with its inputs,
 * `{"t":"call","id":"c0.1","m":"increment","a":[...]}` calls a method its template wires up, on a top-level
 * component or a child. After a change every top-level component renders; the changed ones go back as `{"id","html"}`;
 * a failure as `{"id","error":true}` without details. The circuit keeps running either way.
 */
final class Circuit
{
    // ponytail: fixed limit per tab, an option once an app needs more
    private const MAX_COMPONENTS = 50;

    /** @var array<string, Node> Every component by ID, children included. */
    private array $nodes = [];

    private int $roots = 0;

    /** @var Closure(float, Closure(): void): (Closure(): void) */
    private Closure $every;

    /** This tab's #[Scoped] services; shared by all its components. */
    private Scope $services;

    /**
     * @param Closure(string): void $send Writes one message to the browser.
     * @param Closure(Throwable): void $report Logs a failure.
     * @param (Closure(float, Closure(): void): (Closure(): void))|null $every Starts a timer and returns its cancel; Tenma's setInterval by default.
     */
    public function __construct(private LivePlugin $live, private Closure $send, private Closure $report, ?Closure $every = null)
    {
        $this->services = $live->scope();
        $this->every = $every ?? static fn (float $seconds, Closure $work): Closure => \Tenma::setInterval($work, $seconds)->cancel(...);
    }

    /**
     * The page's render of a top-level component, children included, before any circuit exists. The children's
     * IDs are relative (".0"): the browser puts the top-level ID it picks in front.
     *
     * @param array<string, mixed> $inputs
     * @param string $attributes Further attributes for the tag, already escaped: ' class="big"'
     */
    public static function page(LivePlugin $live, string $selector, array $inputs, string $attributes = ''): string
    {
        $circuit = new self($live, static function (): void {
        }, static function (): void {
        }, static fn (): Closure => static function (): void {
        });
        [$component, $template] = $live->create($selector, $inputs, $circuit->services);
        return sprintf(
            '<%1$s%4$s data-live data-inputs="%2$s">%3$s</%1$s>',
            $selector,
            htmlspecialchars(json_encode((object) $inputs, JSON_THROW_ON_ERROR), ENT_QUOTES),
            $circuit->html($circuit->node('', $selector, $component, $template, null)),
            $attributes,
        );
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

    /** The browser is gone for good: every timer stops, the components are dropped. */
    public function close(): void
    {
        $this->detach();
        $this->nodes = [];
        $this->roots = 0;
    }

    /** The connection dropped, the browser may come back: timers pause, nothing is sent until reattach(). */
    public function detach(): void
    {
        foreach ($this->nodes as $node) {
            foreach ($node->timers as $cancel) {
                $cancel();
            }
            $node->timers = [];
        }
        $this->send = static function (): void {
        };
    }

    /**
     * The browser is back on a new connection: timers run again, and every top-level component renders, since
     * the browser missed whatever changed in between.
     *
     * @param Closure(string): void $send
     */
    public function reattach(Closure $send): void
    {
        $this->send = $send;
        foreach ($this->nodes as $node) {
            $this->startTimers($node);
        }
        foreach ($this->nodes as $node) {
            if ($node->root === null) {
                $this->render($node);
            }
        }
    }

    private function mount(string $id, mixed $selector, mixed $inputs): void
    {
        // A dot belongs to the IDs of children, which the server numbers itself
        if ($id === '' || str_contains($id, '.') || isset($this->nodes[$id]) || $this->roots >= self::MAX_COMPONENTS) {
            throw new InvalidArgumentException("Cannot mount component '$id'");
        }
        if (!is_string($selector) || !is_array($inputs) || ($inputs !== [] && array_is_list($inputs))) {
            throw new InvalidArgumentException('mount needs a selector and inputs as an object');
        }
        [$component, $template] = $this->live->create($selector, $inputs, $this->services);
        $this->roots++;
        $this->render($this->node($id, $selector, $component, $template, null));
    }

    private function call(string $id, mixed $method, mixed $args): void
    {
        $node = $this->nodes[$id] ?? throw new InvalidArgumentException("No component '$id'");
        // Only what the template wires up: a public method elsewhere in the class stays out of reach
        if (!is_string($method) || !in_array($method, $node->template->actions, true) || !is_array($args) || !array_is_list($args)) {
            throw new InvalidArgumentException('call needs a method the template names and a list of arguments');
        }
        $reflection = new ReflectionMethod($node->component, $method);
        if (!$reflection->isPublic() || $reflection->isStatic()) {
            throw new InvalidArgumentException("'$method' is not a public method");
        }
        $reflection->invokeArgs($node->component, $args);
        $this->changed();
    }

    private function node(string $id, string $selector, object $component, Template $template, ?Node $root): Node
    {
        $node = $this->nodes[$id] = new Node($id, $selector, $component, $template, $root);
        $this->startTimers($node);
        return $node;
    }

    private function startTimers(Node $node): void
    {
        foreach ((new ReflectionObject($node->component))->getMethods() as $method) {
            foreach ($method->getAttributes(Every::class) as $attribute) {
                $node->timers[] = ($this->every)($attribute->newInstance()->seconds, function () use ($node, $method): void {
                    try {
                        $method->invoke($node->component);
                        $this->changed();
                    } catch (Throwable $e) {
                        ($this->report)($e);
                    }
                });
            }
        }
    }

    /**
     * After an action or a timer: every top-level component renders, and those whose HTML changed go to the
     * browser. A child calling back into its parent, or a #[Scoped] service shared with another top-level
     * component, needs no bookkeeping that way.
     *
     * ponytail: renders the whole tab on every change; track what each render read once tabs get big
     */
    private function changed(): void
    {
        foreach ($this->nodes as $node) {
            if ($node->root === null) {
                $this->render($node, onlyIfChanged: true);
            }
        }
    }

    private function render(Node $node, bool $onlyIfChanged = false): void
    {
        if (!isset($this->nodes[$node->id])) {
            return;
        }
        $html = $this->html($node);
        if (!$onlyIfChanged || $html !== $node->sent) {
            $node->sent = $html;
            ($this->send)(json_encode(['id' => $node->id, 'html' => $html], JSON_THROW_ON_ERROR));
        }
    }

    /** The node's template, with its children's tags rendered; children no longer placed are removed. */
    private function html(Node $node): string
    {
        $positions = [];
        $placed = [];
        $html = $node->template->render($node->component, function (string $selector, array $inputs) use ($node, &$positions, &$placed): string {
            $key = $inputs['key'] ?? null;
            unset($inputs['key']);
            if ($key !== null && !is_scalar($key)) {
                throw new LogicException("The key of <$selector> must be a scalar");
            }
            // Identity across renders: the key if there is one, otherwise the position among its siblings of that tag
            $key = $selector . ($key !== null ? '=' . $key : '#' . ($positions[$selector] = ($positions[$selector] ?? 0) + 1));
            if (isset($placed[$key])) {
                throw new LogicException("Two <$selector> children with the same key in one template");
            }
            $placed[$key] = true;
            $child = $node->children[$key] ?? null;
            if ($child === null) {
                [$component, $template] = $this->live->create($selector, $inputs, $this->services);
                $child = $node->children[$key] = $this->node($node->id . '.' . $node->next++, $selector, $component, $template, $node->root ?? $node);
            } else {
                // As in Angular: only inputs whose value changed are set again, the child keeps what it changed itself
                $this->live->setInputs($child->component, $selector, array_filter(
                    $inputs,
                    static fn (mixed $value, string|int $name) => !array_key_exists($name, $child->inputs) || $child->inputs[$name] !== $value,
                    ARRAY_FILTER_USE_BOTH,
                ));
            }
            $child->inputs = $inputs;
            return sprintf('<%1$s data-live data-live-id="%2$s">%3$s</%1$s>', $selector, htmlspecialchars($child->id, ENT_QUOTES), $this->html($child));
        });
        foreach (array_diff_key($node->children, $placed) as $key => $gone) {
            $this->remove($gone);
            unset($node->children[$key]);
        }
        return $html;
    }

    private function remove(Node $node): void
    {
        foreach ($node->children as $child) {
            $this->remove($child);
        }
        foreach ($node->timers as $cancel) {
            $cancel();
        }
        unset($this->nodes[$node->id]);
    }
}
