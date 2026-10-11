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
