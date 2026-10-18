<?php

/**
 * A Tenma with publish(), as far as Broadcast can tell: a subscription ends with the request it was made in,
 * one made in Tenma::detach() lives on, and a subscription keeps its process running.
 */

function tenma_pubsub(): void {}

final class Tenma
{
    public static ?int $request = null;

    /** @var list<array{?int, callable}> request, callback */
    public static array $subscriptions = [];

    public static function subscribe(string $channel, callable $callback): void
    {
        self::$subscriptions[] = [self::$request, $callback];
    }

    public static function detach(callable $work): void
    {
        [$request, self::$request] = [self::$request, null];
        $work();
        self::$request = $request;
    }

    public static function publish(string $channel, string $message): void
    {
        foreach (self::$subscriptions as [, $callback]) {
            $callback($message);
        }
    }

    public static function endRequest(int $request): void
    {
        self::$subscriptions = array_values(array_filter(self::$subscriptions, fn (array $s) => $s[0] !== $request));
    }
}
