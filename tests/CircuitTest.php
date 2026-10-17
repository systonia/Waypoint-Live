<?php

namespace Waypoint\Live\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Throwable;
use Waypoint\Container;
use Waypoint\Environment;
use Waypoint\Logger;
use Waypoint\Http\{Request, Response};
use Waypoint\Live\{Circuit, Components, LiveOptions, LivePlugin};
use Waypoint\Live\Tests\Fixtures\{BasketBadge, BasketButton, Chat, Counter, Fragile, Item, ItemList, Label, Bouncer, Room, Shop, Tally, Ticker, Uploader, Visitor};

final class CircuitTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<Throwable> */
    private array $reported = [];

    /** @var list<array{float, Closure, bool}> started timers: seconds, work, cancelled */
    private array $timers = [];

    private function circuit(?Request $request = null, ?LivePlugin $live = null): Circuit
    {
        return new Circuit(
            $live ?? $this->plugin(),
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
            $request,
        );
    }

    private function plugin(bool $dev = false): LivePlugin
    {
        $live = new LivePlugin([Counter::class, ItemList::class, Item::class, BasketButton::class, BasketBadge::class, Fragile::class, Uploader::class, Visitor::class, Bouncer::class, Chat::class, Shop::class, Ticker::class, \Waypoint\Live\Tests\Fixtures\Gauge::class, \Waypoint\Live\Tests\Fixtures\Palette::class, \Waypoint\Live\Tests\Fixtures\Order::class]);
        $container = new Container();
        $container->set(new Label('fine'));
        $container->set(new Room());
        $live->boot($container, self::environment($dev), new Logger(), new LiveOptions());
        return $live;
    }

    /** The app's environment without an app: production unless $dev */
    private static function environment(bool $dev = false): Environment
    {
        return new class ($dev) extends Environment {
            public function __construct(private bool $dev)
            {
            }

            public function isDev(): bool
            {
                return $this->dev;
            }
        };
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
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => [1, 2]]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c9', 'm' => 'add', 'a' => [1]]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c1', 'c' => Counter::class, 'p' => []]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c2', 'c' => 'test-counter', 'p' => ['admin' => true]]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c3', 'c' => 'test-counter', 'p' => ['label' => 'x']]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-counter', 'p' => []]));

        // Errors only, but none for a value that does not fit add(int): refused without a call. After a failed call
        // the unchanged component comes again, which clears the error in the browser
        $this->assertCount(8, array_filter($this->sent, static fn (array $reply) => ($reply['error'] ?? false) === true));
        foreach ($this->sent as $reply) {
            $this->assertTrue(($reply['error'] ?? false) || $reply['html'] === self::html(3));
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

    public function testAFailingTimerShowsOnTheComponentAndWhatItChangedRenders(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'app-ticker', 'p' => []]));
        [, $tick] = $this->timers[0];
        $tick();
        $this->sent = [];
        $tick();
        $this->assertSame([['id' => 'c0', 'error' => true], ['id' => 'c0', 'html' => "<b>2</b>\n"]], $this->sent);
        $this->assertSame('second tick', $this->reported[0]->getMessage());
    }

    public function testInDevAFailedMountLeavesTheEditorWithItsTab(): void
    {
        $live = $this->plugin(true);
        $circuit = $this->circuit(live: $live);
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-fragile', 'p' => ['broken' => true]]));
        $failures = new \ReflectionProperty($live, 'failures');
        $this->assertCount(1, $failures->getValue($live));
        $this->assertTrue($circuit->isEmpty());
        $circuit->close();
        $this->assertSame([], $failures->getValue($live));
    }

    public function testADevWarningIsLoggedOncePerKindThoughItsNumbersChange(): void
    {
        // The logger writes through the app
        \Waypoint\Waypoint::create();
        try {
            $live = $this->plugin(true);
            $live->notice('call "add" took 51.3 ms', 'slow call "add"');
            $live->notice('call "add" took 77.9 ms', 'slow call "add"');
            $this->assertCount(1, (new \ReflectionProperty($live, 'warned'))->getValue($live));
        } finally {
            \Waypoint\Waypoint::reset();
        }
    }

    public function testInDevAPropertyThatIsNanStillRendersAndInspects(): void
    {
        $circuit = $this->circuit(live: $this->plugin(true));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'app-gauge', 'p' => []]));
        $circuit->handle($this->message(['t' => 'inspect', 'id' => 'c0']));
        $this->assertSame("<b>unknown</b>\n", $this->sent[0]['html']);
        $this->assertSame([], $this->sent[0]['state']['c0']);
        $this->assertSame('NAN', end($this->sent)['state']['state']['value']);
        $this->assertSame([], $this->reported);
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

    public function testActionsWithArgumentsGetTheirObjectsAndKeepTheirIdsWhenTheListIsReordered(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-shop', 'p' => []]));
        $ids = static function (string $html): array {
            preg_match_all('/data-on-(?:click|input)="(\w+~\w+)">(\w*)/', $html, $m);
            return $m[1];
        };
        // Per product: remove, rename. The browser sees IDs, not the arguments
        [$removeTea, $renameTea, $removeCoffee] = $ids($this->sent[0]['html']);
        $this->assertStringStartsWith('remove~', $removeTea);
        $this->assertStringStartsWith('rename~', $renameTea);

        // What the event brings comes after the bound object
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => $renameTea, 'a' => ['cocoa']]));
        $this->assertStringContainsString('>cocoa</button>', $this->sent[1]['html']);

        // Reordered: the same object keeps its ID, a click from before the reorder still hits coffee
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'reverse', 'a' => []]));
        $this->assertSame([$removeCoffee, $removeTea], array_values(array_filter($ids($this->sent[2]['html']), static fn (string $id) => str_starts_with($id, 'remove~'))));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => $removeCoffee, 'a' => []]));
        $this->assertStringNotContainsString('coffee', $this->sent[3]['html']);

        // Two renders later coffee's ID is gone: refused without a call, an error or a render
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'reverse', 'a' => []]));
        $this->sent = [];
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => $removeCoffee, 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'remove~0', 'a' => []]));
        $this->assertSame([], $this->sent);

        // The browser brings one value at most: no further parameters of the method
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => $renameTea, 'a' => ['x', 'y']]));
        $this->assertSame([['id' => 'c0', 'error' => true]], $this->sent);
        $this->sent = [];
        $this->reported = [];

        // A method the template only calls with arguments is not reachable by its name
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'remove', 'a' => []]));
        $this->assertSame([['id' => 'c0', 'error' => true]], $this->sent);
        $this->assertCount(1, $this->reported);
    }

    public function testValuesThatCannotChangeKeepTheirIdsWhenEveryRenderBuildsThemAgain(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-palette', 'p' => []]));
        preg_match_all('/data-on-click="(\w+~\w+)"/', $this->sent[0]['html'], $first);

        // Two renders with nothing changed: new Shade objects, the same IDs, so nothing goes to the browser
        $circuit->changed();
        $circuit->changed();
        $this->assertCount(1, $this->sent);

        // And a click on the first render's ID still hits its shade, and the enum case its case
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => $first[1][1], 'a' => []]));
        $this->assertStringContainsString('<p>blue</p>', end($this->sent)['html']);
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => $first[1][2], 'a' => []]));
        $this->assertStringContainsString('<p>Calm</p>', end($this->sent)['html']);
        $this->assertSame([], $this->reported);
    }

    public function testALateClickOnARemovedItemNeverHitsAnItemCreatedLater(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-shop', 'p' => []]));
        preg_match('/data-on-click="(remove~\w+)">coffee/', $this->sent[0]['html'], $coffee);

        // Once coffee is out of both renders' tables, PHP gives its object id to the next new product
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => $coffee[1], 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => $coffee[1], 'a' => []]));

        $this->assertSame(2, substr_count(end($this->sent)['html'], '>new</button>'));
        $this->assertSame([], $this->reported);
    }

    public function testASavedTemplateRendersInEveryTabAndKeepsTheState(): void
    {
        // A component of its own in a temporary directory: its template gets rewritten
        $dir = sys_get_temp_dir() . '/live-hot-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $class = 'HotCounter' . bin2hex(random_bytes(4));
        file_put_contents("$dir/$class.php", "<?php\nnamespace Waypoint\\Live\\Tests\\Fixtures;\n#[\\Waypoint\\Live\\Component(\n    selector: 'test-hot',\n    template: 'Hot.Template.php'\n)]\nfinal class $class\n{\n    public int \$count = 0;\n\n    public function add(): void\n    {\n        \$this->count++;\n    }\n}\n");
        file_put_contents("$dir/Hot.Template.php", '<b data-on-click="add">{{ $this->count }}</b>');
        try {
            require "$dir/$class.php";
            $live = new LivePlugin(["Waypoint\\Live\\Tests\\Fixtures\\$class"]);
            $live->boot(new Container(), self::environment(), new Logger(), new LiveOptions());
            $components = (new \ReflectionProperty($live, 'components'))->getValue($live);
            $circuit = $this->circuit(live: $live);
            $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-hot', 'p' => []]));
            $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => []]));
            $this->assertFalse($components->reload(static fn () => null));

            file_put_contents("$dir/Hot.Template.php", '<i data-on-click="add">{{ $this->count }}</i>');
            $this->assertTrue($components->reload(static fn () => null));
            $circuit->changed();
            $this->assertSame(['id' => 'c0', 'html' => '<i data-on-click="add">1</i>'], end($this->sent));

            // A template that no longer compiles keeps the last one, and is reported
            file_put_contents("$dir/Hot.Template.php", '@if ($this->count) <i>');
            $errors = [];
            $this->assertFalse($components->reload(static function (string $file, ?Throwable $e) use (&$errors): void {
                $errors[] = $e?->getMessage();
            }));
            $this->assertStringContainsString('@if has no @endif', $errors[0]);
            $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => []]));
            $this->assertSame(['id' => 'c0', 'html' => '<i data-on-click="add">2</i>'], end($this->sent));

            // The template shares the class file's imports: a changed import compiles it again
            file_put_contents("$dir/Hot.Template.php", '<i>{{ Mark::class }}</i>');
            $this->assertTrue($components->reload(static fn () => null));
            $circuit->changed();
            $this->assertSame('<i>Waypoint\Live\Tests\Fixtures\Mark</i>', end($this->sent)['html']);
            file_put_contents("$dir/$class.php", str_replace("Fixtures;\n", "Fixtures;\nuse Lento\\Mark;\n", (string) file_get_contents("$dir/$class.php")));
            $this->assertTrue($components->reload(static fn () => null));
            $circuit->changed();
            $this->assertSame('<i>Lento\Mark</i>', end($this->sent)['html']);
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    public function testInDevAnErrorTellsItsTemplateLineAndCauseInProductionNothing(): void
    {
        foreach ([false, true] as $dev) {
            $this->sent = [];
            $live = $this->plugin($dev);
            $circuit = $this->circuit(live: $live);
            $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-fragile', 'p' => []]));
            $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'toggle', 'a' => []]));
            // In dev a trace follows every action, for the console
            $error = array_values(array_filter($this->sent, static fn (array $m) => ($m['t'] ?? null) !== 'trace'))[1]['error'];
            if (!$dev) {
                $this->assertTrue($error);
                continue;
            }
            $this->assertSame(['RuntimeException', 'broken', 'test-fragile', 'call "toggle"', 2], [$error['class'], $error['message'], $error['selector'], $error['cause'], $error['line']]);
            $this->assertStringEndsWith('/Fixtures/Fragile.Template.php', $error['file']);
            $this->assertContains([2, '<button data-on-click="toggle"><?= $this->broken ? throw new \\RuntimeException(\'broken\') : $this->label->text ?></button>'], $error['snippet']);
            $this->assertNotSame([], $error['trace']);

            // The editor gets it at the template's line until the component renders again
            $failures = new \ReflectionProperty($live, 'failures');
            $this->assertSame([[$error['file'], 2, '<test-fragile> call "toggle": RuntimeException: broken']], array_map(static fn (array $f) => array_values($f), array_values($failures->getValue($live))));
            $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'toggle', 'a' => []]));
            $this->assertSame([], $failures->getValue($live));
        }
    }

    public function testInDevEveryActionSendsATraceAndTheConsoleCanInspectAComponent(): void
    {
        foreach ([false, true] as $dev) {
            $this->sent = [];
            $circuit = $this->circuit(live: $this->plugin($dev));
            $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-list', 'p' => []]));
            $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'drop', 'a' => []]));
            $circuit->handle($this->message(['t' => 'inspect', 'id' => 'c0']));
            $traces = array_values(array_filter($this->sent, static fn (array $m) => ($m['t'] ?? null) === 'trace'));
            $inspection = array_values(array_filter($this->sent, static fn (array $m) => ($m['t'] ?? null) === 'inspection'));
            if (!$dev) {
                $this->assertSame([[], []], [$traces, $inspection]);
                continue;
            }
            $this->assertSame(['mount', 'call "drop"'], array_column($traces, 'cause'));
            [$id, $selector, $ms, $bytes] = $traces[1]['renders'][0];
            $this->assertSame(['c0', 'test-list', true, true], [$id, $selector, is_numeric($ms), $bytes > 0]);
            $state = $inspection[0]['state'];
            $this->assertSame(['test-list', ['b'], ['c0.1 <test-item>'], 0], [$state['selector'], $state['state']['items'], $state['children'], $state['timers']]);
        }
    }

    public function testInDevAComponentAndItsChildrenGetTheirStateBackAfterPhpReloaded(): void
    {
        $circuit = $this->circuit(live: $this->plugin(true));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-list', 'p' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0.1', 'm' => 'choose', 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'drop', 'a' => []]));
        $withState = array_values(array_filter($this->sent, static fn (array $m) => isset($m['state'])));
        $state = $withState[count($withState) - 1]['state'];
        // By path: "b" is the first child now, its state is still its own
        $this->assertSame(['c0', 'c0/test-item=b'], array_keys($state));
        $this->assertSame(['items' => ['b'], 'picked' => 'b'], $state['c0']);

        foreach ([true, false] as $dev) {
            $this->sent = [];
            $again = $this->circuit(live: $this->plugin($dev));
            $again->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-list', 'p' => [], 's' => $state + ['c0/test-item=x' => ['clicks' => 9]]]));
            $html = array_values(array_filter($this->sent, static fn (array $m) => isset($m['html'])))[0]['html'];
            // In production the browser never sets state
            $this->assertStringContainsString($dev ? '>b 1</button>' : '>a 0</button>', $html);
            $this->assertStringContainsString($dev ? '<p>picked b</p>' : '<p>picked </p>', $html);
        }
    }

    public function testAPageRefusesInputsThatCannotTravelThroughTheBrowser(): void
    {
        $this->expectExceptionMessage("<test-counter> input 'count': a page passes numbers, strings, booleans, null and arrays of them, not stdClass");
        Circuit::page($this->plugin(), 'test-counter', ['count' => new \stdClass()]);
    }

    public function testValuesFromTheBrowserBecomeTheParametersTypesOrAreRefused(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-order', 'p' => []]));
        $call = fn (string $method, mixed $value) => $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => $method, 'a' => [$value]]));
        // Form text as the type, an empty field as null where null is allowed, a backed enum by its value
        $call('quantity', '7');
        $call('quantity', -3);
        $call('discount', '2.5');
        $call('discount', '');
        $call('gift', 'false');
        $call('gift', true);
        $call('size', 'l');
        // Refused: no cast, no TypeError, no error state, no call
        $call('quantity', '');
        $call('quantity', '7.5');
        $call('quantity', 'abc');
        $call('gift', 'maybe');
        $call('size', 'xl');
        $call('sign', 'not a form');
        // A FromArray DTO from the form's array, its validation on it
        $call('sign', ['email' => 'nope', 'seats' => '3', 'size' => 'l']);

        $order = $circuit->byId('c0')?->component;
        $this->assertInstanceOf(\Waypoint\Live\Tests\Fixtures\Order::class, $order);
        $form = array_pop($order->got);
        $this->assertSame([7, -3, 2.5, null, false, true, \Waypoint\Live\Tests\Fixtures\Size::Large], $order->got);
        $this->assertInstanceOf(\Waypoint\Live\Tests\Fixtures\Signup::class, $form);
        $this->assertSame([3, \Waypoint\Live\Tests\Fixtures\Size::Large], [$form->seats, $form->size]);
        $this->assertArrayHasKey('email', $form->errors);
        $this->assertSame([], $this->reported);
        $this->assertSame([], array_filter($this->sent, static fn (array $reply) => isset($reply['error'])));
    }

    public function testAfterAFailedCallTheNextRenderGoesOutEvenIfItIsTheSame(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-counter', 'p' => ['count' => 3]]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'reset', 'a' => []]));
        $this->sent = [];
        // add(0) changes nothing, but the browser still marks the component as failed: the HTML clears it
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => [0]]));
        $this->assertSame([['id' => 'c0', 'html' => self::html(3)]], $this->sent);
    }

    public function testThePageRendersChildrenWithRelativeIds(): void
    {
        $html = Circuit::page($this->plugin(), 'test-list', []);

        $this->assertStringStartsWith('<test-list data-live data-inputs="{}"><test-item data-live data-live-id=".0">', $html);
        $this->assertStringContainsString('data-live-id=".1"', $html);
    }

    public function testAFailingRenderHoldsBackNoOtherComponent(): void
    {
        $circuit = $this->circuit();
        // A first render that fails leaves nothing behind: the ID is free again
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-fragile', 'p' => ['broken' => true]]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-fragile', 'p' => []]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c1', 'c' => 'test-counter', 'p' => []]));
        $this->assertSame([['id' => 'c0', 'error' => true], ['id' => 'c0', 'html' => "<button data-on-click=\"toggle\">fine</button>\n"]], array_slice($this->sent, 0, 2));

        // c0 breaks: it reports an error, c1 still renders, now and after a reconnect
        $this->sent = [];
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'toggle', 'a' => []]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c1', 'm' => 'add', 'a' => [1]]));
        $this->assertSame([['id' => 'c0', 'error' => true], ['id' => 'c0', 'error' => true], ['id' => 'c1', 'html' => self::html(1)]], $this->sent);
        $resent = [];
        $circuit->detach();
        $circuit->reattach(function (string $message) use (&$resent): void {
            $resent[] = json_decode($message, true);
        });
        $this->assertSame([['id' => 'c0', 'error' => true], ['id' => 'c1', 'html' => self::html(1)]], $resent);
    }

    public function testTagsInARenderedPageBecomeComponents(): void
    {
        $live = $this->plugin();
        $page = '<p><test-counter class="big" count="3"></test-counter><test-list /></p>' . Circuit::page($live, 'test-counter', []);
        $res = (new Response())->withHeader('Content-Type', 'text/html')->write($page);
        $live->after([], Request::fromParts('GET', '/'), $res);

        // An attribute that is no #[Input] stays on the tag
        $this->assertStringStartsWith('<p><test-counter class="big" data-live data-inputs="{&quot;count&quot;:&quot;3&quot;}"><button', $res->getBody());
        $this->assertStringContainsString('<output>3</output>', $res->getBody());
        $this->assertStringContainsString('<test-list data-live data-inputs="{}"><test-item data-live data-live-id=".0">', $res->getBody());
        // A component already rendered, and anything that is not HTML, stays as it is
        $this->assertStringEndsWith(Circuit::page($live, 'test-counter', []), $res->getBody());
        $json = (new Response())->withHeader('Content-Type', 'application/json')->write('"<test-counter></test-counter>"');
        $live->after([], Request::fromParts('GET', '/'), $json);
        $this->assertSame('"<test-counter></test-counter>"', $json->getBody());
    }

    public function testADetachedCircuitPausesAndComesBackWithItsState(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-counter', 'p' => ['count' => 3]]));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => [2]]));
        $this->sent = [];

        $circuit->detach();
        $this->assertTrue($this->timers[0][2]);
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => [1]]));
        $this->assertSame([], $this->sent);

        // A new connection: the browser gets the current render, missed changes included, and the timer runs again
        $resent = [];
        $circuit->reattach(function (string $message) use (&$resent): void {
            $resent[] = json_decode($message, true);
        });
        $this->assertSame([['id' => 'c0', 'html' => self::html(6)]], $resent);
        $this->assertCount(2, $this->timers);
        $this->assertFalse($this->timers[1][2]);
    }

    public function testAScopedServiceIsSharedWithinACircuitAndSeparateBetweenCircuits(): void
    {
        $tab = $this->circuit();
        $tab->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-basket-button', 'p' => []]));
        $tab->handle($this->message(['t' => 'mount', 'id' => 'c1', 'c' => 'test-basket-badge', 'p' => []]));
        $other = $this->circuit();
        $other->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-basket-button', 'p' => []]));
        $other->handle($this->message(['t' => 'mount', 'id' => 'c1', 'c' => 'test-basket-badge', 'p' => []]));
        $this->sent = [];

        // The button's own render did not change: only the other top-level component goes out
        $tab->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => []]));
        $this->assertSame([['id' => 'c1', 'html' => "<output>1</output>\n"]], $this->sent);

        // Another tab counts in its own basket
        $this->sent = [];
        $other->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => []]));
        $this->assertSame([['id' => 'c1', 'html' => "<output>1</output>\n"]], $this->sent);
    }

    public function testAnAppWideServiceThatInjectsAScopedOneIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Tally::class . ' injects #[Scoped] ' . \Waypoint\Live\Tests\Fixtures\Basket::class);
        $this->plugin()->scope()->get(Tally::class);
    }

    public function testABroadcastRendersEveryConnectedTab(): void
    {
        $live = $this->plugin();
        $tab = $this->circuit(null, $live);
        $tab->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-chat', 'p' => []]));
        $other = $this->circuit(null, $live);
        $other->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-chat', 'p' => []]));
        $gone = $this->circuit(null, $live);
        $gone->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-chat', 'p' => []]));
        $gone->detach();
        $this->sent = [];

        // Both connected tabs show the message once; the detached one waits for reattach()
        $tab->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'say', 'a' => ['hi']]));
        $html = "<button data-on-click=\"say\" data-arg=\"hi\">say</button>\nhi";
        $this->assertSame([['id' => 'c0', 'html' => $html], ['id' => 'c0', 'html' => $html]], $this->sent);
        $this->assertSame([], $this->reported);
    }

    public function testFilesFromTheBrowserArriveAsUploadedFiles(): void
    {
        $circuit = $this->circuit();
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-uploader', 'p' => []]));
        $file = static fn (string $name, string $content) => ['$file' => ['name' => $name, 'type' => 'text/plain', 'data' => base64_encode($content)]];

        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'pick', 'a' => [$file('../../etc/a.txt', 'hello')]]));
        $this->assertStringContainsString('<p>a.txt text/plain 5 hello</p>', $this->sent[1]['html']);

        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'save', 'a' => [['title' => 'Trip', 'photos' => [$file('x.jpg', 'x'), $file('y.jpg', '')]]]]));
        $this->assertStringContainsString('<p>Trip: x.jpg, y.jpg</p>', $this->sent[2]['html']);

        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'pick', 'a' => [['$file' => ['name' => 'a', 'data' => '***']]]]));
        $this->assertSame(['id' => 'c0', 'error' => true], $this->sent[3]);

        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'pick', 'a' => [$file('..', '')]]));
        $this->assertStringContainsString('<p> text/plain 0 </p>', $this->sent[4]['html']);

        $many = array_fill(0, 21, $file('x.jpg', 'x'));
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'save', 'a' => [['title' => 'Too many', 'photos' => $many]]]));
        $this->assertSame(['id' => 'c0', 'error' => true], $this->sent[5]);
    }

    public function testAComponentSeesItsConnectionsRequestAndSendsTheBrowserElsewhere(): void
    {
        $circuit = $this->circuit(Request::fromParts('GET', '/_live', cookies: ['who' => 'ada']));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-visitor', 'p' => []]));
        $this->assertStringStartsWith('<p>ada</p>', $this->sent[0]['html']);

        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'leave', 'a' => ['/admin']]));
        $this->assertSame(['t' => 'navigate', 'url' => '/admin'], end($this->sent));

        // Another site is no destination, however it is spelled: browsers drop tabs and line breaks from a URL
        foreach (['//evil.test', "/\t/evil.test", "/\n/evil.test", "/\r\n/evil.test", '/\\evil.test', 'https://evil.test', 'javascript:alert(1)'] as $url) {
            $this->sent = [];
            $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'leave', 'a' => [$url]]));
            $this->assertSame([['id' => 'c0', 'error' => true]], array_values(array_filter($this->sent, static fn (array $m) => isset($m['error']))), json_encode($url) ?: '');
        }

        // A reconnect brings the new connection's request
        $circuit->detach();
        $resent = [];
        $circuit->reattach(function (string $message) use (&$resent): void {
            $resent[] = json_decode($message, true);
        }, Request::fromParts('GET', '/_live', cookies: ['who' => 'bo']));
        $this->assertStringStartsWith('<p>bo</p>', $resent[0]['html']);

        // And the page's own render reads the page's request
        $this->assertStringContainsString('<p>cy</p>', Circuit::page($this->plugin(), 'test-visitor', [], '', Request::fromParts('GET', '/', cookies: ['who' => 'cy'])));
    }

    public function testAnotherPluginAddsItsComponentsAtBoot(): void
    {
        $live = new LivePlugin([Counter::class]);
        $container = new Container();
        $container->set(new Label('fine'));
        $live->boot($container, self::environment(), new Logger(), new LiveOptions());
        // What a plugin that requires LivePlugin does in its own boot()
        $container->get(Components::class)->add(ItemList::class, Item::class);

        $this->assertStringContainsString('<test-item data-live data-live-id=".0">', Circuit::page($live, 'test-list', []));
        $this->expectException(\LogicException::class);
        $container->get(Components::class)->add(Fragile::class);
    }

    public function testACircuitResumesOnlyForTheUserItWasOpenedFor(): void
    {
        $ada = Request::fromParts('GET', '/_live');
        $ada->jwt = ['sub' => 1];
        $circuit = $this->circuit($ada);
        $bo = Request::fromParts('GET', '/_live');
        $bo->jwt = ['sub' => 2];

        $this->assertTrue($circuit->sameUser($ada));
        $this->assertFalse($circuit->sameUser($bo));
        $this->assertFalse($circuit->sameUser(Request::fromParts('GET', '/_live')));
        $this->assertTrue($this->circuit()->sameUser(Request::fromParts('GET', '/_live')));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTheBroadcastSubscribesOnlyOnceATabConnects(): void
    {
        // A Tenma with publish(), as far as Broadcast can tell; a subscription keeps its process running
        eval('function tenma_pubsub(): void {} final class Tenma { public static array $subscribed = []; public static function subscribe(string $channel, callable $callback): void { self::$subscribed[] = $channel; } }');
        $live = $this->plugin();
        $this->assertSame([], \Tenma::$subscribed);

        $this->circuit(live: $live);
        $this->circuit(live: $live);
        $this->assertSame(['waypoint-live'], \Tenma::$subscribed);
    }

    public function testGuardsOfTheComponentAndItsActionsCheckTheConnectionsRequest(): void
    {
        $live = new LivePlugin([\Waypoint\Live\Tests\Fixtures\Vault::class]);
        $container = new Container();
        $container->bind(\Waypoint\PermissionProvider::class, new class implements \Waypoint\PermissionProvider {
            public function hasPermission(mixed $jwt, string $permission): bool
            {
                return is_array($jwt) && in_array($permission, (array) ($jwt['can'] ?? []), true);
            }
        });
        $live->boot($container, self::environment(), new Logger(), new LiveOptions());
        $request = static function (?array $jwt): Request {
            $request = Request::fromParts('GET', '/_live');
            $request->jwt = $jwt;
            return $request;
        };
        $mount = $this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-vault', 'p' => []]);
        $call = fn (string $method) => $this->message(['t' => 'call', 'id' => 'c0', 'm' => $method, 'a' => []]);

        // #[Authenticated] on the class: nobody signed in mounts nothing, the page neither
        $this->circuit($request(null), $live)->handle($mount);
        $this->assertInstanceOf(\Waypoint\Exceptions\UnauthorizedException::class, $this->reported[0] ?? null);
        try {
            Circuit::page($live, 'test-vault', [], '', $request(null));
            $this->fail('The page rendered a guarded component for nobody');
        } catch (\Waypoint\Exceptions\UnauthorizedException) {
        }

        // Signed in: the class's guard holds, the methods' are checked before each call
        $this->reported = [];
        $circuit = $this->circuit($request(['sub' => 1, 'role' => 'user', 'can' => ['vault.open']]), $live);
        $circuit->handle($mount);
        $circuit->handle($call('peek'));
        $circuit->handle($call('wipe'));
        $circuit->handle($call('open'));
        $this->assertSame(['peek', 'open'], $circuit->byId('c0')?->component->done);
        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(\Waypoint\Exceptions\ForbiddenException::class, $this->reported[0]);
    }

    public function testALoginThatExpiredClosesTheConnectionInsteadOfACall(): void
    {
        $request = Request::fromParts('GET', '/_live');
        $request->jwt = ['sub' => 1, 'exp' => time() + 60];
        $closed = 0;
        $circuit = new Circuit($this->plugin(), function (string $message): void {
            $this->sent[] = json_decode($message, true);
        }, function (Throwable $e): void {
            $this->reported[] = $e;
        }, static fn (): Closure => static function (): void {
        }, $request, function () use (&$closed): void {
            $closed++;
        });
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c0', 'c' => 'test-counter', 'p' => ['count' => 1]]));
        $request->jwt['exp'] = time() - 1;
        $this->sent = [];
        $circuit->handle($this->message(['t' => 'call', 'id' => 'c0', 'm' => 'add', 'a' => ['1']]));
        $circuit->handle($this->message(['t' => 'mount', 'id' => 'c1', 'c' => 'test-counter', 'p' => []]));

        // Neither ran: the connection closes, and the browser connects again through the app's middlewares
        $this->assertSame(2, $closed);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->reported);
        $this->assertSame(1, $circuit->byId('c0')?->component->count);
        $this->assertNull($circuit->byId('c1'));
    }

    public function testNavigatingWhileThePageRendersIsAnError(): void
    {
        $this->expectException(\LogicException::class);
        Circuit::page($this->plugin(), 'test-bouncer', []);
    }

    public function testTheViewHelperRendersWithThePagesRequest(): void
    {
        $live = $this->plugin();
        $middleware = $live->middlewares()[0];
        $this->assertInstanceOf(\Waypoint\Http\MiddlewareBase::class, $middleware);
        $middleware->handle(Request::fromParts('GET', '/', cookies: ['who' => 'dee']), new Response(), static fn () => null);

        $html = ($live->helpers()['live'])('test-visitor');
        $this->assertInstanceOf(\Waypoint\Html::class, $html);
        // A view escapes what an echo tag prints: the markup of the component stays markup
        $this->assertStringContainsString('<p>dee</p>', \Waypoint\Http\View::escape($html));
    }

    public function testSelectorAndTemplateFollowTheClassName(): void
    {
        $components = (new Components())->add(\Waypoint\Live\Tests\Fixtures\CitySign::class);
        $this->assertSame(['app-city-sign'], $components->selectors());
        $this->assertStringEndsWith('/Fixtures/CitySign.Template.php', $components->sources()['app-city-sign'][1]);
    }

    public function testTheCheckFindsTheMistakesOfAllComponentsWithTheirFiles(): void
    {
        $components = (new Components())->add(Counter::class, \Waypoint\Live\Tests\Fixtures\Careless::class);
        $this->assertSame([
            'Careless.Template.php:1 data-on-click="tik": Careless has no method tik(). Did you mean tick()?',
            'Careless.php:15 #[Every] tick(): a timer calls its method without arguments',
            'Careless.php:20 #[Every] never(): the seconds must be more than 0',
            'Careless.php:12 #[Input] $hidden is not public: the page and parent templates set inputs from outside',
        ], array_map(static fn (array $p) => basename($p['file']) . ":{$p['line']} {$p['message']}", $components->check()));
    }

    public function testAPageWarnsOfTagsThatStayAndAttributesCloseToAnInput(): void
    {
        $components = ['test-counter' => Counter::class, 'test-item' => Item::class];
        $this->assertSame([
            "<test-countr> is no component. Did you mean <test-counter>? Or is it missing from new LivePlugin([...])?",
            "<test-item> stays as it is: a component's tag needs attributes in double quotes and an empty body, <test-item ...></test-item>",
            "<test-counter cuont>: 'cuont' stays an attribute of the tag, it is no #[Input]. Did you mean count?",
        ], \Waypoint\Live\Problems::inPage('<test-countr></test-countr><test-item name=\'a\'></test-item><test-counter data-live cuont="3" class="big"></test-counter><my-widget>', $components));
    }

    public function testAComponentWithoutItsTemplateFailsAtBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Components())->add(\Waypoint\Live\Tests\Fixtures\Orphan::class);
    }

    public function testTextThatIsNoUtf8IsPrintedWithReplacementCharacters(): void
    {
        $this->assertSame("ok\u{FFFD} &lt;", \Waypoint\Live\Template::escape("ok\xff <"));
    }

    public function testHtmlIsPrintedAsItIsAndEverythingElseEscaped(): void
    {
        $this->assertSame('<b>bold</b>&lt;i&gt;', \Waypoint\Live\Template::escape(new \Waypoint\Live\Html('<b>bold</b>'), '<i>'));
        $this->assertSame('<b>bold</b>', \Waypoint\Live\Template::escape(new \Waypoint\Html('<b>bold</b>')));
    }
}
