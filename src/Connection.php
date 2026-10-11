<?php

namespace Waypoint\Live;

use InvalidArgumentException;
use Waypoint\Http\Request;

/**
 * The browser connection of a circuit, for a component or a #[Scoped] service that injects it: the request the
 * WebSocket came in with (its cookies, and $request->jwt as the app's middlewares left it), and a way to send
 * the browser to another page. After a reconnect, $request is the new connection's.
 */
#[Scoped]
final class Connection
{
    private ?string $navigation = null;

    public function __construct(public private(set) Request $request)
    {
    }

    /**
     * After this action, the browser loads $url: a path on this site ("/admin"). It counts even if the action
     * throws afterwards, so a check can send a logged-out user to the login and stop the action. Not during the
     * page's first render, only from actions and timers.
     */
    public function navigate(string $url): void
    {
        // A path of our own, never another site: "//evil.test" or "javascript:" would leave it, and so would
        // "/\t/evil.test", since browsers drop tabs and line breaks from a URL
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new InvalidArgumentException("navigate() takes a path on this site, not '$url'");
        }
        $this->navigation = $url;
    }

    /** @internal The circuit came back on a new connection. */
    public function reconnected(Request $request): void
    {
        $this->request = $request;
    }

    /** @internal What navigate() asked for, once. */
    public function takeNavigation(): ?string
    {
        [$url, $this->navigation] = [$this->navigation, null];
        return $url;
    }
}
