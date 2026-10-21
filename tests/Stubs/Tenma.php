<?php

/**
 * A Tenma with channels, as far as Broadcast can tell: a subscription ends with the request it was made in,
 * one made in Task::detach() lives on, and a subscription keeps its process running. FakeTenma holds what
 * the tests look at.
 */

namespace {
    function tenma_pubsub(): void {}

    final class FakeTenma
    {
        public static ?int $request = null;

        /** @var list<array{?int, callable}> request, callback */
        public static array $subscriptions = [];

        public static function endRequest(int $request): void
        {
            self::$subscriptions = array_values(array_filter(self::$subscriptions, fn (array $s) => $s[0] !== $request));
        }
    }
}

namespace Messaging {
    final class Channel
    {
        public function __construct(public readonly string $name) {}

        public function subscribe(callable $callback): void
        {
            \FakeTenma::$subscriptions[] = [\FakeTenma::$request, $callback];
        }

        public function publish(string $message): void
        {
            foreach (\FakeTenma::$subscriptions as [, $callback]) {
                $callback($message);
            }
        }
    }
}

namespace Threading {
    final class Task
    {
        public static function detach(callable $work): void
        {
            [$request, \FakeTenma::$request] = [\FakeTenma::$request, null];
            $work();
            \FakeTenma::$request = $request;
        }
    }
}
