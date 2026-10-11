<?php

namespace Waypoint\Live\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Throwable;
use Waypoint\Live\{Action, Circuit, Component};

final class Counter extends Component
{
    public int $count = 0;
    public bool $unmounted = false;

    public function mount(array $props): void
    {
        $this->count = is_int($props['start'] ?? null) ? $props['start'] : 0;
    }

    #[Action]
    public function add(int $by): void
    {
        $this->count += $by;
    }

    /** Public, but not an #[Action]: the browser must not reach it. */
    public function reset(): void
    {
        $this->count = 0;
    }

    public function render(): string
    {
        return "<output>{$this->count}</output>";
    }

    public function pushNow(): void
    {
        $this->changed();
    }
}

final class CircuitTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<Throwable> */
    private array $reported = [];

    /** @var array<string, Counter> */
    private array $created = [];

    private function circuit(): Circuit
    {
        return new Circuit(
            function (string $class): Component {
                if ($class !== Counter::class) {
                    throw new InvalidArgumentException("'$class' is not allowed");
                }
                return $this->created[] = new Counter();
            },
            function (string $message): void {
                $this->sent[] = json_decode($message, true);
            },
            function (Throwable $e): void {
                $this->reported[] = $e;
            },
        );
    }

    private function message(array $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    public function testMountRendersAndAnActionRendersAgain(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => Counter::class, 'p' => ['start' => 3]]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => [2]]));

        $this->assertSame([
            ['id' => 'c0', 'html' => '<output>3</output>'],
            ['id' => 'c0', 'html' => '<output>5</output>'],
        ], $this->sent);
        $this->assertSame([], $this->reported);
    }

    public function testTheBrowserReachesOnlyActionsAndAllowedClasses(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => Counter::class, 'p' => ['start' => 3]]));
        $this->sent = [];

        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'reset', 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'mount', 'a' => [['start' => 99]]]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'render', 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => ['not a number']]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c9', 'm' => 'add', 'a' => [1]]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c1', 'c' => \stdClass::class, 'p' => []]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => Counter::class, 'p' => []]));

        $this->assertCount(7, $this->sent);
        foreach ($this->sent as $reply) {
            $this->assertTrue($reply['error'] ?? false);
            $this->assertArrayNotHasKey('html', $reply);
        }
        $this->assertCount(7, $this->reported);
        $this->assertSame(3, $this->created[0]->count);
    }

    public function testGarbageIsIgnoredWithoutAReply(): void
    {
        $circuit = $this->circuit();
        $circuit->handle('not json');
        $circuit->handle('[1, 2]');
        $circuit->handle('{"t":"mount"}');

        $this->assertSame([], $this->sent);
    }

    public function testAComponentPushesUntilTheCircuitCloses(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => Counter::class, 'p' => []]));
        $this->sent = [];

        $this->created[0]->count = 7;
        $this->created[0]->pushNow();
        $circuit->close();
        $this->created[0]->pushNow();

        $this->assertSame([['id' => 'c0', 'html' => '<output>7</output>']], $this->sent);
    }
}
