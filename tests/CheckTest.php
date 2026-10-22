<?php

namespace Waypoint\Live\Tests;

use PHPUnit\Framework\TestCase;
use Waypoint\Live\TemplateChecks;
use Waypoint\View\Check;

final class CheckTest extends TestCase
{
    public function testPhpstanReadsTheTemplatesWithThisAsTheirComponentAtTheirLines(): void
    {
        $found = array_filter(Check::phpstan(dirname(__DIR__)), static fn (array $p) => str_ends_with($p['file'], '/Sloppy.Template.php'));
        $this->assertSame([
            '3 Access to an undefined property Waypoint\Live\Tests\Fixtures\Item::$nmae.',
            '3 Access to an undefined property Waypoint\Live\Tests\Fixtures\Sloppy::$cuont.',
            '6 Access to an undefined property Waypoint\Live\Tests\Fixtures\Item::$nmae.',
            '7 Access to an undefined property Waypoint\Live\Tests\Fixtures\Sloppy::$lat.',
        ], array_values(array_map(static fn (array $p) => "{$p['line']} {$p['message']}", $found)));
    }

    public function testPhpstanResolvesNamesWithTheClassFilesImports(): void
    {
        $this->assertSame([], array_values(array_filter(Check::phpstan(dirname(__DIR__)), static fn (array $p) => str_ends_with($p['file'], '/Board.Template.php'))));
    }

    public function testProblemsCoverEveryComponentOfTheProject(): void
    {
        $this->assertContains('#[Every] tick(): a timer calls its method without arguments', array_column((new TemplateChecks(dirname(__DIR__)))->problems(), 'message'));
    }
}
