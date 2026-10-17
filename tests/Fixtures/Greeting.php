<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Live\{Component, Every};
use Waypoint\Support\FiberLocal;

/** Reads a value of the request, as a translator reads the locale */
#[Component(
    selector: 'test-greeting',
    template: 'Greeting.Template.php'
)]
final class Greeting
{
    /** @var list<mixed> The locale each action and timer saw */
    public array $seen = [];

    /** @return FiberLocal<string> */
    public static function locale(): FiberLocal
    {
        static $locale = null;
        return $locale ??= new FiberLocal();
    }

    public function look(): void
    {
        $this->seen[] = self::locale()->get();
    }

    #[Every(1.0)]
    public function tick(): void
    {
        $this->seen[] = self::locale()->get();
    }
}
