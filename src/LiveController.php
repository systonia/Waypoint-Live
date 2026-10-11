<?php

namespace Waypoint\Live;

use Throwable;
use Waypoint\Attributes\{Controller, Get, Inject};
use Waypoint\Exceptions\ForbiddenException;
use Waypoint\Http\{Request, Response};
use Waypoint\Logger;

/**
 * The browser's WebSocket: one Circuit per connection. A normal route, so the app's middlewares and guards
 * run before the handover; the handler then runs with the request's context (request id in the log).
 */
#[Controller]
final class LiveController
{
    // ponytail: fixed per worker, Blazor's defaults; options once an app needs others
    private const PARK_SECONDS = 180.0;
    private const MAX_PARKED = 100;

    /** @var array<string, array{Circuit, \Tenma\Timer}> Circuits whose browser may come back, oldest first. */
    private static array $parked = [];

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
        $res->upgrade(\Tenma\Http\Response::websocket(static function (\Tenma\Http\WebSocket $socket) use ($live, $logger): void {
            $send = static function (string $message) use ($socket): void {
                try {
                    $socket->send($message);
                } catch (\Tenma\Http\ConnectionClosed) {
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
                    $resumed = $circuit !== null;
                    $token = $resumed ? $asked : bin2hex(random_bytes(16));
                    $circuit ??= new Circuit($live, $send, static fn (Throwable $e) => $logger->error('live: ' . $e->getMessage(), ['exception' => $e]));
                    $send(json_encode(['t' => 'circuit', 'token' => $token, 'resumed' => $resumed], JSON_THROW_ON_ERROR));
                    if ($resumed) {
                        $circuit->reattach($send);
                    }
                }
            } finally {
                if ($circuit !== null) {
                    self::park($token, $circuit);
                }
            }
        }));
    }

    /**
     * Keeps a circuit whose connection dropped, so the browser can come back to it: like Blazor Server, for a
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
        \Tenma::detach(static function () use ($token, &$expiry): void {
            $expiry = \Tenma::setTimeout(static fn () => self::unpark($token)?->close(), self::PARK_SECONDS);
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
