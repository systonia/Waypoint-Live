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
            $circuit = new Circuit(
                $live,
                static function (string $message) use ($socket): void {
                    try {
                        $socket->send($message);
                    } catch (\Tenma\Http\ConnectionClosed) {
                        // A timer rendered after the browser left; the receive loop below ends the circuit
                    }
                },
                static fn (Throwable $e) => $logger->error('live: ' . $e->getMessage(), ['exception' => $e]),
            );
            try {
                while (($message = $socket->receive()) !== null) {
                    $circuit->handle($message);
                }
            } finally {
                $circuit->close();
            }
        }));
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
