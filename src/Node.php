<?php

namespace Waypoint\Live;

use Closure;

/** @internal One component in a circuit: a top-level one from the page, or a child placed by a template. */
final class Node
{
    /** @var array<string, Node> By selector plus `key`, or plus position when there is no key. */
    public array $children = [];

    /** @var array<array-key, mixed> The inputs the parent's template passed last time. */
    public array $inputs = [];

    /** @var list<Closure(): void> Functions that cancel this component's timers. */
    public array $timers = [];

    /**
     * @var array<string, array{string, list<mixed>}> The calls with arguments its last render bound, by the ID the
     *     browser sends: method and arguments
     */
    public array $calls = [];

    /** @var array<string, array{string, list<mixed>}> Those of the render before: a click may still be on its way. */
    public array $lastCalls = [];

    /** A top-level component's last render sent to the browser. */
    public ?string $sent = null;

        /** Numbers the children: their ID is this one's plus ".n". */
    public int $next = 0;

    public function __construct(
        public readonly string $id,
        public readonly string $selector,
        public readonly object $component,
        public readonly Template $template,
        public readonly ?Node $root,
    ) {
    }
}
