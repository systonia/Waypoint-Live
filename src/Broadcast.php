<?php

namespace Waypoint\Live;

use WeakMap;

/**
 * Renders every connected tab again: for state that tabs share through an app-wide service, such as a chat. A
 * tab only renders after its own actions and timers; `$broadcast->changed()` after a change reaches the others.
 * Inject it into a component or a service, or take it with `$app->get(Broadcast::class)`.
 *
 * ponytail: per worker, every tab; with several workers the others need a way in (Redis pub/sub), and a topic
 * per broadcast once rendering every tab costs too much
 */
final class Broadcast
{
    /** @var WeakMap<Circuit, true> The circuits whose browser is connected. */
    private WeakMap $circuits;

    public function __construct()
    {
        $this->circuits = new WeakMap();
    }

    /** Every connected tab renders, and sends the components whose HTML changed. */
    public function changed(): void
    {
        // A render may wait on a query while a tab connects or leaves: go through the tabs as they were
        $circuits = [];
        foreach ($this->circuits as $circuit => $_) {
            $circuits[] = $circuit;
        }
        foreach ($circuits as $circuit) {
            $circuit->changed();
        }
    }

    /** @internal */
    public function connect(Circuit $circuit): void
    {
        $this->circuits[$circuit] = true;
    }

    /** @internal */
    public function disconnect(Circuit $circuit): void
    {
        unset($this->circuits[$circuit]);
    }
}
