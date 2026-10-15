<?php

namespace Waypoint\Live;

use WeakMap;

/**
 * Renders every connected tab again: for state that tabs share through an app-wide service, such as a chat. A
 * tab only renders after its own actions and timers; `$broadcast->changed()` after a change reaches the others.
 * Inject it into a component or a service, or take it with `$app->get(Broadcast::class)`.
 *
 * Under a Tenma with Tenma::publish(), the other workers render their tabs too, and with `tenma --pubsub` every
 * process on that Redis.
 *
 * ponytail: every tab of every app on the channel; a topic per broadcast once rendering every tab costs too much
 */
final class Broadcast
{
    private const CHANNEL = 'waypoint-live';

    /** @var WeakMap<Circuit, true> The circuits whose browser is connected. */
    private WeakMap $circuits;

    public function __construct()
    {
        $this->circuits = new WeakMap();
        if (self::acrossWorkers()) {
            \Tenma::subscribe(self::CHANNEL, fn () => $this->changedHere());
        }
    }

    /** Every connected tab renders, and sends the components whose HTML changed. */
    public function changed(): void
    {
        if (self::acrossWorkers()) {
            \Tenma::publish(self::CHANNEL, '');
        } else {
            $this->changedHere();
        }
    }

    /** Outside Tenma (tests) or under a Tenma without publish(): only this worker's tabs. */
    private static function acrossWorkers(): bool
    {
        // Tenma's own function behind Tenma::publish(), there since publish() is
        return function_exists('tenma_pubsub');
    }

    /**
     * Only this worker's tabs render: in a Tenma::subscribe() callback that has just brought this worker's state
     * up to date. Every worker runs it, so changed() would render every tab once per worker.
     */
    public function changedHere(): void
    {
        // A render may wait on a query while a tab connects or leaves: go through the tabs as they were
        $circuits = [];
        foreach ($this->circuits as $circuit => $_) {
            $circuits[] = $circuit;
        }
        foreach ($circuits as $circuit) {
            $circuit->changed(cause: 'a broadcast');
        }
    }

    /** @internal In dev: a saved template that does not compile, or null once it does again, to this worker's tabs. */
    public function problem(string $file, ?\Throwable $e): void
    {
        foreach ($this->circuits as $circuit => $_) {
            $circuit->problem($file, $e);
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
