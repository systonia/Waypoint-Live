<?php

namespace Waypoint\Live\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Throwable;
use Waypoint\Container;
use Waypoint\Live\{Circuit, LivePlugin};
use Waypoint\Live\Tests\Fixtures\Counter;

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
        $live = new LivePlugin([Counter::class]);
        $live->boot(new Container());
        return new Circuit(
            $live->create(...),
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

    private function message(array $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    private static function html(int $count): string
    {
        return "<button data-on-click=\"add\" data-arg=\"1\">&lt;b&gt;</button>\n<output>$count</output>\n";
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
}
