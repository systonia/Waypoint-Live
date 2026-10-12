<?php

namespace Waypoint\Live\Tests\Fixtures;

/** One for the whole app, unlike Basket: every tab sees the same messages. */
final class Room
{
    /** @var list<string> */
    public array $messages = [];
}
