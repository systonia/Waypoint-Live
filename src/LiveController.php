<?php

namespace Waypoint\Live;

use Throwable;
use Waypoint\Attributes\{Controller, Get, Ignore, Inject};
use Waypoint\Exceptions\ForbiddenException;
use Waypoint\Http\{Request, Response};
use Waypoint\Logger;

/**
 * @internal The browser's WebSocket: one Circuit per connection, left out of the OpenAPI document. A normal route, so the app's middlewares and guards
 * run before the handover; the handler then runs with the request's context (request id in the log).
 */
#[Controller]
#[Ignore]
final class LiveController
{
    // ponytail: fixed per worker; options once an app needs others
    private const PARK_SECONDS = 180.0;
    private const MAX_PARKED = 100;

    /** @var array<string, array{Circuit, \Threading\Timer}> Circuits whose browser may come back, oldest first. */
    private static array $parked = [];

    /** This process, in dev: the browser sees a new one after a restart (--watch) and loads the page again. */
    private static ?string $boot = null;

    #[Inject]
    private LivePlugin $live;

    #[Inject]
    private Logger $logger;

    #[Get('/_live')]
    public function connect(Request $req, Response $res): void
    {
        // A WebSocket carries no CSRF header and the browser sends cookies along from any site: only our own pages
        if (!self::sameOrigin($req)) {
            throw new ForbiddenException('Live components only connect from this site');
        }
        $live = $this->live;
        $logger = $this->logger;
        $res->upgrade(\Http\Response::websocket(static function (\Http\WebSocket $socket) use ($live, $logger, $req): void {
            $send = static function (string $message) use ($socket): void {
                try {
                    $socket->send($message);
                } catch (\Http\ConnectionClosed) {
                    // Rendered after the browser left; the receive loop below ends the connection
                }
            };
            $circuit = null;
            $token = '';
            try {
                while (($message = $socket->receive()) !== null) {
                    if ($circuit !== null) {
                        $circuit->handle($message);
                        continue;
                    }
                    // The first message says whether the browser had a circuit before: {"t":"hello","token":"..."|null}
                    $hello = json_decode($message, true);
                    $asked = is_array($hello) && is_string($hello['token'] ?? null) ? $hello['token'] : '';
                    $circuit = self::unpark($asked);
                    // Someone else, or nobody any more after a logout: the components start over for this user
                    if ($circuit !== null && !$circuit->sameUser($req)) {
                        $circuit->close();
                        $circuit = null;
                    }
                    $resumed = $circuit !== null;
                    $token = $resumed ? $asked : bin2hex(random_bytes(16));
                    $close = static fn () => $socket->close();
                    $circuit ??= new Circuit($live, $send, static fn (Throwable $e) => $logger->error('live: ' . $e->getMessage(), ['exception' => $e]), null, $req, $close);
                    $send(json_encode(['t' => 'circuit', 'token' => $token, 'resumed' => $resumed] + ($live->isDev() ? ['boot' => self::$boot ??= bin2hex(random_bytes(8)), 'reload' => $live->reloaded(), 'editor' => $live->editor()] : []), JSON_THROW_ON_ERROR));
                    if ($resumed) {
                        $circuit->reattach($send, $req, $close);
                    }
                }
            } finally {
                // A circuit without components has nothing to come back to, and must not push real ones out
                if ($circuit !== null && !$circuit->isEmpty()) {
                    self::park($token, $circuit);
                } else {
                    $circuit?->close();
                }
            }
        }));
    }

    /**
     * Keeps a circuit whose connection dropped, so the browser can come back to it, for a
     * while, then it is closed. Per worker: a browser that reconnects to another worker starts over.
     */
    private static function park(string $token, Circuit $circuit): void
    {
        $circuit->detach();
        if (count(self::$parked) >= self::MAX_PARKED) {
            self::unpark((string) array_key_first(self::$parked))?->close();
        }
        // Detached from this request: the connection is over, and Tenma ends the request's own timers with it
        $expiry = null;
        \Threading\Task::detach(static function () use ($token, &$expiry): void {
            $expiry = \Threading\Timer::after(self::PARK_SECONDS, static fn () => self::unpark($token)?->close());
        });
        if ($expiry !== null) {
            self::$parked[$token] = [$circuit, $expiry];
        }
    }

    private static function unpark(string $token): ?Circuit
    {
        [$circuit, $expiry] = self::$parked[$token] ?? [null, null];
        unset(self::$parked[$token]);
        $expiry?->cancel();
        return $circuit;
    }

    private static function sameOrigin(Request $req): bool
    {
        $origin = parse_url($req->headers['Origin'] ?? '');
        $host = $req->headers['Host'] ?? '';
        if (!is_array($origin) || !isset($origin['host']) || $host === '') {
            return false;
        }
        $originHost = $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '');
        return strcasecmp($originHost, $host) === 0;
    }
}
