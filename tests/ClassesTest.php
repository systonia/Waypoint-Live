<?php

namespace Waypoint\Live\Tests;

use PHPUnit\Framework\TestCase;
use Waypoint\Live\Template;
use Waypoint\Live\Tests\Fixtures\Board;

use function Waypoint\Live\classes;

final class ClassesTest extends TestCase
{
    public function testStringsAndConditionsBecomeOneClassList(): void
    {
        $this->assertSame('row px-4', classes('row px-4'));
        $this->assertSame('row late bg-green-100 font-bold', classes('row', ['late' => true, 'gone' => false, 'bg-green-100 font-bold' => 1]));
        $this->assertSame('a b c', classes(['a', 'b' => 'yes'], 'c'));
        // Each class once, in order; whitespace of any kind; falsy values and empty strings add nothing
        $this->assertSame('a b c', classes("  a\tb ", ['b a', "c\n" => true]));
        $this->assertSame('', classes('', ['x' => 0, 'y' => '', 'z' => null, 'w' => [], 0 => null, 1 => '']));
        $this->assertSame('', classes());
        $this->assertSame('md:w-1/2 data-[state=open]:block', classes(['md:w-1/2' => true, 'data-[state=open]:block' => true]));
    }

    public function testEveryTemplateCallsItWithoutAnImport(): void
    {
        $this->assertStringContainsString('use function Waypoint\classes;', Template::header(null));
        $this->assertStringContainsString('use function Waypoint\classes;', Template::header(Board::class));
        eval(Template::compile('<p class="{{ classes(\'a\', [\'b\' => true]) }}">', [], class: Board::class, first: 'return; '));
    }
}
