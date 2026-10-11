<?php

namespace Waypoint\Live\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Throwable;
use Waypoint\Container;
use Waypoint\Live\{Circuit, LivePlugin};
use Waypoint\Live\Tests\Fixtures\{Counter, Item, ItemList};

final class CircuitTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<Throwable> */
    private array $reported = [];

    /** @var list<array{float, Closure, bool}> started timers: seconds, work, cancelled */
    private array $timers = [];

    private function circuit(): Circuit
    {
        return new Circuit(
            $this->plugin(),
            function (string $message): void {
                $this->sent[] = json_decode($message, true);
            },
            function (Throwable $e): void {
                $this->reported[] = $e;
            },
            function (float $seconds, Closure $work): Closure {
                $i = count($this->timers);
                $this->timers[] = [$seconds, $work, false];
                return function () use ($i): void {
                    $this->timers[$i][2] = true;
                };
            },
        );
    }

    private function plugin(): LivePlugin
    {
        $live = new LivePlugin([Counter::class, ItemList::class, Item::class]);
        $live->boot(new Container());
        return $live;
    }

    private function message(array $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    private static function html(int $count): string
    {
        // An echo tag escapes, an echo statement stays raw
        return "<button data-on-click=\"add\" data-arg=\"1\">&lt;b&gt;</button>\n<output>$count</output>\n<b>";
    }

    public function testMountRendersTheTemplateEscapedAndAMethodRendersAgain(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-counter', 'p' => ['count' => 3]]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => ['2']]));

        $this->assertSame([
            ['id' => 'c0', 'html' => self::html(3)],
            ['id' => 'c0', 'html' => self::html(5)],
        ], $this->sent);
        $this->assertSame([], $this->reported);
    }

    public function testTheBrowserReachesOnlyTemplateMethodsInputsAndListedSelectors(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-counter', 'p' => ['count' => 3]]));
        $this->sent = [];

        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'reset', 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'tick', 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => ['not a number']]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c9', 'm' => 'add', 'a' => [1]]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c1', 'c' => Counter::class, 'p' => []]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c2', 'c' => 'test-counter', 'p' => ['admin' => true]]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c3', 'c' => 'test-counter', 'p' => ['label' => 'x']]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-counter', 'p' => []]));

        $this->assertCount(8, $this->sent);
        foreach ($this->sent as $reply) {
            $this->assertTrue($reply['error'] ?? false);
            $this->assertArrayNotHasKey('html', $reply);
        }
        $this->assertCount(8, $this->reported);
    }

    public function testGarbageIsIgnoredWithoutAReply(): void
    {
        $circuit = $this->circuit();
        $circuit->handle('not json');
        $circuit->handle('[1, 2]');
        $circuit->handle('{"t":"mount"}');

        $this->assertSame([], $this->sent);
    }

    public function testAnEveryMethodPushesUntilTheCircuitCloses(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-counter', 'p' => []]));
        $this->sent = [];

        $this->assertCount(1, $this->timers);
        [$seconds, $tick] = $this->timers[0];
        $this->assertSame(1.0, $seconds);
        $tick();
        $this->assertSame([['id' => 'c0', 'html' => self::html(1)]], $this->sent);

        $circuit->close();
        $this->assertTrue($this->timers[0][2]);
    }

    public function testChildrenGetIdsKeepTheirStateAndCallBackIntoTheParent(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-list', 'p' => []]));
        $this->assertSame(
            "<test-item data-live data-live-id=\"c0.0\"><button data-on-click=\"choose\">a 0</button>\n</test-item>\n"
            . "<test-item data-live data-live-id=\"c0.1\"><button data-on-click=\"choose\">b 0</button>\n</test-item>\n"
            . "<p>picked </p>\n<button data-on-click=\"drop\">drop</button>\n",
            $this->sent[0]['html'],
        );
        $this->assertCount(2, $this->timers);

        // The child's click calls the parent back: the whole top-level component renders again
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0.1', 'm' => 'choose', 'a' => []]));
        $this->assertSame('c0', $this->sent[1]['id']);
        $this->assertStringContainsString('b 1</button>', $this->sent[1]['html']);
        $this->assertStringContainsString('<p>picked b</p>', $this->sent[1]['html']);

        // The parent drops "a": "b" keeps its ID and its state, "a" is gone with its timer
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'drop', 'a' => []]));
        $this->assertStringStartsWith('<test-item data-live data-live-id="c0.1"><button data-on-click="choose">b 1</button>', $this->sent[2]['html']);
        $this->assertStringNotContainsString('c0.0', $this->sent[2]['html']);
        $this->assertTrue($this->timers[0][2]);
        $this->assertFalse($this->timers[1][2]);

        $this->sent = [];
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0.0', 'm' => 'choose', 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'pick', 'a' => ['x']]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0.5', 'c' => 'test-item', 'p' => []]));
        $this->assertSame([['id' => 'c0.0', 'error' => true], ['id' => 'c0', 'error' => true], ['id' => 'c0.5', 'error' => true]], $this->sent);
    }

    public function testThePageRendersChildrenWithRelativeIds(): void
    {
        $html = Circuit::page($this->plugin(), 'test-list', []);

        $this->assertStringStartsWith('<test-list data-live data-inputs="{}"><test-item data-live data-live-id=".0">', $html);
        $this->assertStringContainsString('data-live-id=".1"', $html);
    }
}
