<?php

namespace Waypoint\Live\Tests;

use PHPUnit\Framework\TestCase;
use Waypoint\Live\Editor;
use Waypoint\Live\Tests\Fixtures\{Counter, Item, ItemList, Shop};

/** The repo is the project: its autoload-dev directory holds the fixture components. */
final class EditorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures';

    private function editor(): Editor
    {
        return new Editor(dirname(__DIR__));
    }

    /** @return list<string> */
    private function labels(string $file, string $text): array
    {
        return array_column($this->editor()->completions($file, $text, strlen($text)), 'label');
    }

    /** @return list<string> */
    private function problems(string $file, string $text): array
    {
        return array_map(static fn (array $p) => "{$p['line']} {$p['severity']}: {$p['message']}", $this->editor()->diagnostics($file, $text));
    }

    public function testThisInATemplateIsItsComponent(): void
    {
        $this->assertSame(Counter::class, $this->editor()->thisClass(self::FIXTURES . '/Counter.Template.php'));
        $this->assertSame(ItemList::class, $this->editor()->thisClass(self::FIXTURES . '/../Fixtures/ItemList.Template.php'));
        $this->assertNull($this->editor()->thisClass(self::FIXTURES . '/Counter.php'));
    }

    public function testActionsCompleteThePublicMethods(): void
    {
        $this->assertSame(['add', 'reset'], $this->labels(self::FIXTURES . '/Counter.Template.php', '<button data-on-click="'));
        $this->assertSame(['add', 'reset'], $this->labels(self::FIXTURES . '/Counter.Template.php', '<input data-on-input="ad'));
        // Outside a template there is no component to call
        $this->assertSame([], $this->labels(self::FIXTURES . '/view.php', '<button data-on-click="'));
    }

    public function testTagsCompleteTheSelectors(): void
    {
        $selectors = $this->labels(self::FIXTURES . '/view.php', '<p><test-');
        $this->assertContains('test-counter', $selectors);
        $this->assertContains('test-item', $selectors);
        $this->assertNotContains('test-orphan', $selectors);
    }

    public function testAttributesCompleteTheInputsNotYetThere(): void
    {
        $template = self::FIXTURES . '/ItemList.Template.php';
        $this->assertSame(['name', 'pick', 'key'], $this->labels($template, '<test-item '));
        $this->assertSame(['pick', 'key'], $this->labels($template, '<test-item name="<?= $item ?>" '));
        // A view has no keys
        $this->assertSame(['count'], $this->labels(self::FIXTURES . '/view.php', "<test-counter\n    "));
        // Inside a value, nothing
        $this->assertSame([], $this->labels($template, '<test-item name="'));
    }

    public function testDefinitionLeadsFromTagsAndActionsToTheCode(): void
    {
        $text = '<test-item key="a"></test-item><button data-on-click="drop">';
        $tag = $this->editor()->definition(self::FIXTURES . '/ItemList.Template.php', $text, 3);
        $this->assertSame(['file' => (new \ReflectionClass(Item::class))->getFileName(), 'line' => 12], $tag);
        $action = $this->editor()->definition(self::FIXTURES . '/ItemList.Template.php', $text, strpos($text, 'drop') + 1);
        $this->assertSame(['file' => (new \ReflectionClass(ItemList::class))->getFileName(), 'line' => 24], $action);
        $this->assertNull($this->editor()->definition(self::FIXTURES . '/ItemList.Template.php', $text, strpos($text, 'button')));
        $call = $this->editor()->definition(self::FIXTURES . '/Shop.Template.php', '<b data-on-click="remove($product)">', 20);
        $this->assertSame(['file' => (new \ReflectionClass(Shop::class))->getFileName(), 'line' => 22], $call);
    }

    public function testTheFixtureTemplatesHaveNoProblems(): void
    {
        foreach (['Counter', 'ItemList', 'Item', 'Shop', 'Board'] as $name) {
            $file = self::FIXTURES . "/$name.Template.php";
            $this->assertSame([], $this->problems($file, (string) file_get_contents($file)), $name);
        }
    }

    public function testProblemsInActions(): void
    {
        $this->assertSame([
            '2 error: data-on-click="ad": Counter has no method ad(). Did you mean add()?',
            '3 error: data-on-click="tick": Counter::tick() is not public, the browser cannot call it',
            '4 error: data-on-click="add(": name a method, or call one with arguments: remove($item). A " ends the attribute: use \' for strings',
            '5 error: data-on-click="ad(1)": Counter has no method ad(). Did you mean add()?',
        ], $this->problems(self::FIXTURES . '/Counter.Template.php', "<p>\n<b data-on-click=\"ad\">\n<b data-on-click=\"tick\">\n<b data-on-click=\"add(\"1\")\">\n<b data-on-click=\"ad(1)\"><b data-on-click=\"add(2)\">"));
        // The arguments are PHP: checked like an echo, at their line
        $this->assertSame(["2 error: syntax error, unexpected token \"]\""], $this->problems(self::FIXTURES . '/Counter.Template.php', "<p>\n<b data-on-click=\"add(1 +)\">"));
    }

    public function testProblemsInChildTags(): void
    {
        $this->assertSame([
            "1 error: <test-item nme>: 'nme' is not an #[Input] of Item. Did you mean name?",
            "2 error: <test-item colour>: 'colour' is not an #[Input] of Item. Inputs: name, pick",
            '3 error: <test-item> stays as it is: a component\'s tag needs attributes in double quotes and an empty body, <test-item ...></test-item>',
            '4 warning: <test-iten> is no component. Did you mean <test-item>?',
        ], $this->problems(self::FIXTURES . '/ItemList.Template.php', implode("\n", [
            '<test-item nme="a"></test-item>',
            '<test-item key="<?= $x ?>" colour="red" />',
            "<test-item name='a'></test-item>",
            '<test-iten></test-iten><my-widget></my-widget>',
        ])));
    }

    public function testProblemsInTemplatesWithDirectives(): void
    {
        $file = self::FIXTURES . '/Shelf.Template.php';
        $this->assertSame([], $this->problems($file, (string) file_get_contents($file)));
        $this->assertSame([
            '1 error: @foreach has no @endforeach',
            "2 error: <test-item nme>: 'nme' is not an #[Input] of Item. Did you mean name?",
            '3 error: data-on-click="pik": Shelf has no method pik(). Did you mean pick()?',
        ], $this->problems($file, "@foreach (\$this->books as \$book)\n<test-item nme=\"{{ \$book }}\"></test-item>\n<b data-on-click=\"pik\">{{ \$book }}</b>"));
        // PHP inside {{ }} is checked like PHP in an echo tag
        $this->assertSame(["2 error: Unmatched ')'"], $this->problems($file, "<p>\n{{ \$this->open) }}</p>"));
    }

    public function testDirectivesGetTheirColours(): void
    {
        $text = "{{/* a\nb */}}\n@if (\$this->open)\n  {{ \$book->title() }} @@\n@endif";
        $tokens = array_map(
            static fn (array $t) => "{$t['line']}:{$t['column']} " . substr(explode("\n", $text)[$t['line'] - 1], $t['column'], $t['length']) . " {$t['type']}",
            $this->editor()->tokens(self::FIXTURES . '/Shelf.Template.php', $text),
        );
        $this->assertSame([
            '1:0 {{/* a comment',
            '2:0 b */}} comment',
            '3:0 @if keyword',
            '3:5 $this variable',
            '3:12 open property',
            '4:2 {{ keyword',
            '4:20 }} keyword',
            '4:5 $book variable',
            '4:12 title method',
            '5:0 @endif keyword',
        ], array_values(array_filter($tokens, static fn (string $t) => !str_ends_with($t, ' operator'))));
        $this->assertContains('4:10 -> operator', $tokens);
        $call = $this->editor()->tokens(self::FIXTURES . '/Shop.Template.php', '<b data-on-click="remove($product)">');
        $this->assertSame([[1, 18, 6, 'method'], [1, 25, 8, 'variable']], array_map(static fn (array $t) => [$t['line'], $t['column'], $t['length'], $t['type']], $call));
        $this->assertSame([], $this->editor()->tokens(self::FIXTURES . '/view.php', $text));
    }

    /** @return list<string> line:column text type, operators left out */
    private function colours(string $text): array
    {
        $tokens = array_map(
            static fn (array $t) => "{$t['line']}:{$t['column']} " . substr(explode("\n", $text)[$t['line'] - 1], $t['column'], $t['length']) . " {$t['type']}",
            $this->editor()->tokens(self::FIXTURES . '/Shelf.Template.php', $text),
        );
        return array_values(array_filter($tokens, static fn (string $t) => !str_ends_with($t, ' operator')));
    }

    public function testCodeGetsItsColours(): void
    {
        $this->assertSame(
            ['1:0 @code keyword', '2:0 @endcode keyword', '1:6 $a variable', '1:11 count function', '1:17 $this variable', '1:24 books property'],
            $this->colours("@code \$a = count(\$this->books);\n@endcode"),
        );
        $this->assertSame(['1:4 @class keyword', '1:11 md:w-1/2 string', '1:21 $a variable'], $this->colours('<li @class.md:w-1/2="$a">'));
        $this->assertSame(['1:0 @use keyword', '1:6 App\\Status class', '1:17 as keyword', '1:20 State class'], $this->colours('@use (App\\Status as State)'));
    }

    public function testProblemsWithPhp(): void
    {
        $this->assertSame(
            ['2 error: A line comment inside <?= ?> also comments out what the template adds before ?>: use /* */'],
            $this->problems(self::FIXTURES . '/Item.Template.php', "<p>\n<?= \$this->name // the name ?>\n</p>"),
        );
        // The line in the template, though the compiled code runs through eval
        $this->assertSame(
            ["4 error: Unmatched ')'"],
            $this->problems(self::FIXTURES . '/Item.Template.php', "<p>\n\n<?php if (\$this->name): ?>\n<?= \$this->name) ?>\n<?php endif ?>"),
        );
    }
}
