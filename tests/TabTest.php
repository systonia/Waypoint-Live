<?php

namespace Waypoint\Live\Tests;

use PHPUnit\Framework\TestCase;
use Waypoint\App;
use Waypoint\Live\LivePlugin;
use Waypoint\Live\Testing\Tab;
use Waypoint\Live\Tests\Fixtures\{Basket, BasketBadge, Counter, Item, ItemList, Door, Shop, Uploader};
use Waypoint\Waypoint;

final class TabTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        Waypoint::reset();
        $this->app = Waypoint::create();
        $this->app->plugin(new LivePlugin([Counter::class, ItemList::class, Item::class, Shop::class, Uploader::class, Door::class, BasketBadge::class]));
        $this->app->attach([]);
    }

    protected function tearDown(): void
    {
        Waypoint::reset();
    }

    public function testClicksGoThroughTheTemplateAndTimersRunOnFakeTime(): void
    {
        $tab = Tab::for($this->app);
        $counter = $tab->mount('test-counter', ['count' => 1]);
        $counter->click('add')->assertSee('2')->assertDontSee('admin');
        $this->assertSame(2, $counter->component()->count);
        $counter->click('button')->assertSee('3');
        $tab->advance(2.5);
        $this->assertSame(5, $counter->component()->count);
        // The template escapes what it prints: assertSee looks for the escaped text
        $counter->assertSee('<b>');
    }

    public function testAMethodTheTemplateDoesNotWireFails(): void
    {
        $counter = Tab::for($this->app)->mount('test-counter');
        $this->expectExceptionMessage('Nothing in <c0> has data-on-click="reset"');
        $counter->click('reset');
    }

    public function testChildrenBoundArgumentsFilesAndNavigation(): void
    {
        $tab = Tab::for($this->app);
        $list = $tab->mount('test-list');
        $list->find('test-item', 'b')->click('choose')->assertSee('b 1');
        $list->assertSee('picked b');

        $shop = $tab->mount('test-shop');
        $shop->click('remove')->assertDontSee('tea')->assertSee('coffee');
        $shop->input('input', 'cocoa')->assertSee('cocoa');

        $tab->mount('test-uploader')->upload('input', 'a.txt', 'hello', 'text/plain')->assertSee('a.txt text/plain 5 hello')
            ->submit('form', ['title' => 'Trip'])->assertSee('Trip:');

        $tab->mount('app-door')->click('leave');
        $tab->assertNavigatedTo('/elsewhere');
    }

    public function testAFakeThatExtendsAScopedServiceTakesItsPlace(): void
    {
        $tab = Tab::for($this->app)->with(new class extends Basket {
            public int $items = 7;
        });
        $tab->mount('test-basket-badge')->assertSee('7');

        $this->expectExceptionMessage('is no #[Scoped] service');
        $tab->with(new \stdClass());
    }

    public function testAnErrorFailsTheTestWithItsOwnStack(): void
    {
        $this->expectExceptionMessage('the door is stuck');
        Tab::for($this->app)->mount('app-door')->click('jam');
    }

    public function testThePageRendersWithoutAConnection(): void
    {
        $this->assertStringStartsWith('<test-counter data-live data-inputs="{&quot;count&quot;:7}"><button', Tab::page($this->app, 'test-counter', ['count' => 7]));
    }
}
