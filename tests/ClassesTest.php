<?php

namespace Waypoint\Live\Tests;

use PHPUnit\Framework\TestCase;
use Waypoint\Live\Template;
use Waypoint\Live\Tests\Fixtures\Board;

final class ClassesTest extends TestCase
{
    public function testEveryTemplateCallsItWithoutAnImport(): void
    {
        $this->assertStringContainsString('use function Waypoint\classes;', Template::header(null));
        $this->assertStringContainsString('use function Waypoint\classes;', Template::header(Board::class));
        eval(Template::compile('<p class="{{ classes(\'a\', [\'b\' => true]) }}">', [], class: Board::class, first: 'return; '));
    }
}
