<?php

namespace Waypoint\Live\Tests;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Waypoint\Live\{Directives, Problems, Template};
use Waypoint\Live\Tests\Fixtures\{Board, Shelf};

final class DirectivesTest extends TestCase
{
    /** @return list<string> */
    private static function problems(string $source): array
    {
        return array_map(static fn (array $p) => "{$p['line']}: {$p['message']}", (new Directives($source))->problems);
    }

    public function testDirectivesBecomePhpOnTheSameLines(): void
    {
        $source = "@foreach (\$this->items as \$item)\n  {{ \$item }}\n  @if (\$a === ')')\n  {{ \$html }}\n  @elseif (\$b)\n  @else\n  @endif\n@endforeach\n{{/* one\ntwo */}} {{ /* three */ }}";
        $php = (new Directives($source))->compile();
        $this->assertSame(
            "<?php foreach (\$this->items as \$item): ?>\n  <?=  \$item , \"\\n\" ?>\n  <?php if (\$a === ')'): ?>\n  <?=  \$html , \"\\n\" ?>\n  <?php elseif (\$b): ?>\n  <?php else: ?>\n  <?php endif ?>\n<?php endforeach ?>\n<?php /* one\ntwo */ ?> <?php /* three */ ?>",
            $php,
        );
        $this->assertSame(substr_count($source, "\n"), substr_count($php, "\n"));
    }

    public function testAChildTagOverSeveralLinesKeepsTheLinesAfterIt(): void
    {
        $source = "<app-row\n  key=\"{{ \$a }}\"\n  name=\"x\ny\"\n></app-row>\n{{ \$b }}\n";
        $php = Template::compile($source, ['app-row']);
        $this->assertSame(substr_count($source, "\n"), substr_count($php, "\n"));
        $this->assertStringContainsString('$b', explode("\n", $php)[5]);
    }

    public function testAnEchoAtALineEndInsideAChildsAttributeKeepsItsNewline(): void
    {
        $php = Template::compile("<app-row name=\"{{ \$a }}\n{{ \$b }}\"></app-row>", ['app-row']);
        // It parses, and the value is $a, a newline and $b
        eval('return; ' . $php);
        $this->assertMatchesRegularExpression("/'name' => '' \\. \\(string\\) \\(\\s*\\\$a\\s*\\) \\. '\n' \\. \\(string\\) \\(\\s*\\\$b\\s*\\)/", $php);
    }

    public function testWindowsLineEndsAfterAnEchoOrAChildTagStay(): void
    {
        $render = static function (string $source): string {
            ob_start();
            eval(Template::compile($source, ['app-row']));
            return (string) ob_get_clean();
        };
        // PHP swallows a CRLF after a closing tag as it does a LF: the compiler echoes back the one that was there
        $this->assertSame("<p>\r\na\r\nb\r\n</p>\r\n", $render("<p>\r\n{{ 'a' }}\r\n{{ 'b' }}\r\n</p>\r\n"));
        $__child = static fn (string $selector, array $inputs): string => "[{$inputs['name']}]";
        $this->assertSame("[x]\r\n<p>\r\n", (static function () use ($__child): string {
            ob_start();
            eval(Template::compile("<app-row name=\"x\"></app-row>\r\n<p>\r\n", ['app-row']));
            return (string) ob_get_clean();
        })());
        // Inside a child's attribute the newline stays in the value
        $php = Template::compile("<app-row name=\"{{ \$a }}\r\n{{ \$b }}\"></app-row>", ['app-row']);
        $this->assertMatchesRegularExpression("/'name' => '' \\. \\(string\\) \\(\\s*\\\$a\\s*\\) \\. '\r\n' \\. \\(string\\) \\(\\s*\\\$b\\s*\\)/", $php);
    }

    public function testTextAndPhpStayAsTheyAre(): void
    {
        foreach ([
            'mail@example.com',
            '<style>@media (max-width: 9px) { a { b: c } }</style>',
            '<button @click="go">',
            '<?php $a = "{{ x }}"; @$b[1]; ?>',
            '<?= "@if (1)" ?>',
        ] as $source) {
            $this->assertSame($source, (new Directives($source))->compile(), $source);
            $this->assertSame([], self::problems($source), $source);
        }
        $this->assertSame('@if and @', (new Directives('@@if and @@'))->compile());
    }

    public function testATemplateWithDirectivesRenders(): void
    {
        $template = new Template(__DIR__ . '/Fixtures/Shelf.Template.php', ['test-item']);
        $this->assertSame(['pick'], $template->actions);
        $children = [];
        $html = $template->render(new Shelf(), function (string $selector, array $inputs) use (&$children): string {
            $children[] = $inputs;
            return "<$selector>";
        });
        $this->assertSame(
            "<ul>\n    <li data-on-click=\"pick\" data-arg=\"Dune\">Dune</li>\n    <li data-on-click=\"pick\" data-arg=\"&lt;Emma&gt;\">&lt;Emma&gt;</li>\n</ul>\n<test-item>\n<hr>\nmail@example.com @if\n",
            $html,
        );
        // A lone {{ }} in a child's attribute passes the value itself
        $this->assertSame('Dune', $children[0]['key']);
        $this->assertInstanceOf(Closure::class, $children[0]['pick']);
    }

    public function testMarkupInACommentNamesNoActionAndBindsNoCall(): void
    {
        $source = "<button data-on-click=\"save\">\n{{/* <button data-on-click=\"wipe\"> */}}\n"
            . "<!-- <button data-on-click=\"nuke\"> <?= 1 ?> <b data-on-click=\"remove(\$x)\"> -->\n"
            . "<?php /* <button data-on-click=\"drop\"> */ ?>\n<b data-on-click=\"pick\"><i data-on-click=\"go(\$y)\">";
        $actions = [];
        $php = Template::compile($source, [], $actions);
        $this->assertSame(['save', 'pick'], $actions);
        $this->assertSame(1, substr_count($php, '$__action('));
        $this->assertStringContainsString("\$__action('go', [\$y])", $php);
    }

    public function testBlocksMustCloseInOrder(): void
    {
        $this->assertSame(['1: @foreach has no @endforeach'], self::problems("@foreach (\$a as \$b)\n"));
        $this->assertSame(
            ['3: @endforeach without @foreach: the @if of line 2 is still open'],
            self::problems("@foreach (\$a as \$b)\n@if (\$c)\n@endforeach\n"),
        );
        $this->assertSame(['1: @else without @if', '1: @endif without @if'], self::problems('@else @endif'));
    }

    public function testMistakesAreReportedAtTheirLine(): void
    {
        $this->assertSame(['2: @forech is no directive and stays text. Did you mean @foreach?'], self::problems("<p>\n@forech (\$a as \$b)"));
        $this->assertSame('warning', (new Directives('@endiff'))->problems[0]['severity']);
        $this->assertSame(['1: @if needs a condition: @if ($ok)'], self::problems('@if open @endif'));
        $this->assertSame(['1: @if ( has no )'], self::problems('@if ($a === "(" '));
        $this->assertSame(['2: {{ has no }}'], self::problems("\n{{ \$a "));
        $this->assertSame(['1: {{/* has no */}}'], self::problems('{{/* a */'));
        $this->assertSame(['1: {{ }} is empty: put an expression between them'], self::problems('{{ }}'));
        $this->assertSame(['1: {{ }} has a /* without */: it would comment out the template up to the next */'], self::problems('<p>{{ $a /* euros }}</p>{{/* b */}}'));
        $this->assertSame(['1: @if ( ) has a /* without */: it would comment out the template up to the next */'], self::problems('@if ($a /* b) @endif'));
        $this->assertSame([], self::problems('{{ $a /* euros */ }}'));
        // A string may hold the closing braces
        $this->assertSame([], self::problems("{{ '}}' }}"));
        $this->assertSame([], self::problems('<b @on="x">@id @lento</b>'));
    }

    public function testCodeBecomesPhpOnTheSameLines(): void
    {
        $source = "@code\n    \$a = '@endcode ?>' . \"}}\";\n    if (\$a) { \$b = 1; } /* @endcode */\n@endcode\n<p>{{ \$a }}</p>\n@code \$c = {{ 1 }} @endcode";
        $php = (new Directives($source))->compile();
        $this->assertSame(
            "<?php \n    \$a = '@endcode ?>' . \"}}\";\n    if (\$a) { \$b = 1; } /* @endcode */\n ?>\n<p><?=  \$a  ?></p>\n<?php  \$c = {{ 1 }}  ?>",
            $php,
        );
        $this->assertSame([], self::problems($source));
        // The body is PHP, not template: no directive inside it
        $this->assertSame("<?php  if (\$a) { @if }  ?>", (new Directives('@code if ($a) { @if } @endcode'))->compile());
    }

    public function testATemplateSetsAVariableInCodeAndAnErrorInItKeepsItsLine(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'live');
        file_put_contents($file, "@code\n    \$title = strtoupper(\$this->books[0]);\n@endcode\n<h1>{{ \$title }}</h1>\n@code\n\n    \$this->open ?: throw new \\RuntimeException('closed');\n@endcode");
        $template = new Template($file);
        $render = static fn (Shelf $shelf) => $template->render($shelf, static fn () => '');
        $this->assertSame("<h1>DUNE</h1>\n", $render(new Shelf()));
        $shelf = new Shelf();
        $shelf->open = false;
        try {
            $render($shelf);
            $this->fail('No error');
        } catch (\RuntimeException $e) {
            $this->assertSame([$file, 7], Template::origin($e));
        } finally {
            unlink($file);
        }
    }

    public function testMistakesInCode(): void
    {
        $this->assertSame(['2: @code has no @endcode'], self::problems("<p>\n@code \$a = 1;\n"));
        $this->assertSame(['1: @endcode without @code'], self::problems('<p> @endcode'));
        $this->assertSame(['1: @code has a ?>, which would end the PHP early: use @endcode to end the code'], self::problems('@code $a = 1; ?> <p> @endcode'));
        $this->assertSame(['1: @code has a /* without */: it would comment out the template up to the next */'], self::problems('@code $a = 1; /* @endcode'));
        $this->assertSame('1: @code is empty', self::problems("@code \n @endcode")[0]);
        $this->assertSame('warning', (new Directives('@code @endcode'))->problems[0]['severity']);
        // A string may hold @endcode
        $this->assertSame(['1: @code has no @endcode'], self::problems("@code \$a = '@endcode';"));
    }

    public function testATemplateResolvesNamesAsItsClassFileDoes(): void
    {
        $template = new Template(__DIR__ . '/Fixtures/Board.Template.php', [], Board::class);
        $this->assertSame("<p>Calm OPEN open <b> Calm Closed</p>\n<button data-on-click=\"toggle\" class=\"btn phase-[open]:ring\">Toggle</button>\n", $template->render(new Board(), static fn () => ''));
        // Group and function imports one by one, the trait in the class body is none
        $this->assertSame(
            'namespace Waypoint\Live\Tests\Fixtures; use Waypoint\Live\Component; use Waypoint\Live\Html as Markup; '
                . 'use Waypoint\Live\Tests\Fixtures\Sub\Phase; use function strtoupper as shout; use function Waypoint\\classes; use Waypoint; ',
            Template::header(Board::class),
        );
        // The header shares the first line: errors keep theirs
        $this->assertSame(["2 Unmatched ')'"], array_map(
            static fn (array $p) => "{$p['line']} {$p['message']}",
            Problems::inTemplate("<p>\n{{ Phase::Open) }}", Board::class, []),
        ));
    }

    public function testUseImportsAClassForTheTemplate(): void
    {
        $source = "@use (Waypoint\\Live\\Tests\\Fixtures\\Sub\\Phase as Markup)\n@use (\\Waypoint\\Live\\Html)\n<p>{{ Markup::Closed->name }} {{ new Html('<b>') }}</p>";
        $directives = new Directives($source);
        $this->assertSame("<?php ?>\n<?php ?>\n<p><?=  Markup::Closed->name  ?> <?=  new Html('<b>')  ?></p>", $directives->compile());
        $this->assertSame(['Markup' => 'Waypoint\\Live\\Tests\\Fixtures\\Sub\\Phase', 'Html' => 'Waypoint\\Live\\Html'], $directives->uses);
        // The template's alias wins over the class file's Html as Markup
        $header = Template::header(Board::class, $directives->uses);
        $this->assertStringContainsString('use Waypoint\\Live\\Tests\\Fixtures\\Sub\\Phase as Markup; ', $header);
        $this->assertStringNotContainsString('Html as Markup', $header);
        eval(Template::compile($source, [], class: Board::class, first: 'return; '));
        $file = (string) tempnam(sys_get_temp_dir(), 'live');
        file_put_contents($file, $source);
        try {
            $this->assertSame('<p>Closed <b></p>', (new Template($file, [], Board::class))->render(new Board(), static fn () => ''));
        } finally {
            unlink($file);
        }
    }

    public function testMistakesInUse(): void
    {
        $this->assertSame(['2: @use needs a class: @use (App\\Status)'], self::problems("\n@use App\\Status"));
        $this->assertSame(['1: @use (App\\Status as): name a class, as in @use (App\\Status) or @use (App\\Status as State)'], self::problems('@use (App\\Status as)'));
        $this->assertSame(['2: @use (Waypoint\\Live\\Template as html): html is imported above already'], self::problems("@use (Waypoint\\Live\\Html)\n@use (Waypoint\\Live\\Template as html)"));
        $missing = (new Directives('@use (App\\Missing)'))->problems;
        $this->assertSame(['1: @use (App\\Missing): there is no class, enum, interface or trait App\\Missing'], self::problems('@use (App\\Missing)'));
        $this->assertSame('warning', $missing[0]['severity']);
        // Inside @code it is PHP; @user in a text is no typo
        $this->assertSame([], (new Directives("@code \$a = '@use (A)'; @endcode Hi @user"))->problems);
    }

    public function testPhpThatWouldBeAFatalErrorOnRenderIsRefused(): void
    {
        // Each of them ends the worker in eval, which cannot catch a fatal error of PHP's compiler
        $this->assertSame([
            "2: use in a template: it has the imports of its component's class file, and @use (App\\Status) adds one",
            '3: A named function in a template is declared again on the next render, which PHP refuses: use a closure or a method of the component',
            '4: A class in a template is declared again on the next render, which PHP refuses: declare it in a file of its own',
            '5: An enum in a template is declared again on the next render, which PHP refuses: declare it in a file of its own',
            "6: namespace in a template: it has the namespace of its component's class",
            '7: declare in a template: PHP takes it only at the start of a file',
            '8: break outside a loop: PHP refuses it before the template runs',
            '9: continue 2: only 1 loop is around it',
            '10: break takes a positive whole number of loops, or none',
            '11: break outside a loop: PHP refuses it before the template runs',
            '12: yield outside a function: a template is no generator',
            '13: $this is the component: a template cannot assign or unset it',
        ], self::problems(implode("\n", [
            '<p>',
            '@code use Waypoint\\Live\\Input; @endcode',
            '@code function fmt($x) { return $x; } @endcode',
            '<?php class Row {} ?>',
            '<?php enum State {} ?>',
            '<?php namespace App; ?>',
            '<?php declare(strict_types=1); ?>',
            '@if ($a) <?php break ?> @endif',
            '@foreach ($a as $b) <?php continue 2 ?> @endforeach',
            '<?php foreach ($a as $b) { break $b; } ?>',
            '<?php $f = function () { break; }; ?>',
            '<?php yield 1; ?>',
            '<?php $this = null; ?>',
        ])));
        // What is fine: loops in every syntax, closures, methods of an anonymous class, ::class
        $this->assertSame([], self::problems(implode("\n", [
            '@foreach ($a as $b) <?php if ($b) break; ?> @endforeach',
            '<?php foreach ($a as $b) { if ($b) { continue; } } ?>',
            '<?php for ($i = 0; $i < 3; $i++) break; ?>',
            '<?php do { break; } while (false); ?>',
            '<?php while (true): break; endwhile ?>',
            '<?php switch ($a): case 1: break; endswitch ?>',
            '<?php $f = function () use ($a) { foreach ($a as $b) { yield $b; } }; ?>',
            '<?php $o = new class { use Countable; public function f(): void {} }; $c = Row::class; $this->a = 1; ?>',
        ])));
        // A template compiles only without them
        $this->expectException(InvalidArgumentException::class);
        Template::compile('<?php break ?>', []);
    }

    public function testConditionalClassesGoIntoTheClassAttribute(): void
    {
        $call = static fn (string $static, string $conditions) => "\\Waypoint\\classes({$static}[$conditions])";
        $caught = '(string) ob_get_clean(), ';
        // The attribute's value goes through the same classes() call as the conditions
        $this->assertSame(
            '<li class="<?php ob_start() ?>row<?php echo ' . $call($caught, "(\$o->late) ? 'late' : '', (\$o->ready()) ? 'bg-green-100' : ''") . ' ?>">',
            (new Directives('<li class="row" @class.late="$o->late" @class.bg-green-100="$o->ready()">'))->compile(),
        );
        $this->assertSame('<li class="<?php echo ' . $call('', "(\$a) ? 'late' : ''") . ' ?>">', (new Directives('<li @class.late="$a">'))->compile());
        // A class attribute that holds {{ }}, or one that is empty
        $this->assertSame(
            '<li class="<?php ob_start() ?>row <?=  $x  ?><?php echo ' . $call($caught, "(\$b) ? 'a' : ''") . ' ?>">',
            (new Directives('<li class="row {{ $x }}" @class.a="$b">'))->compile(),
        );
        $this->assertSame("<li class='<?php ob_start() ?><?php echo " . $call($caught, "(1) ? 'a' : ''") . " ?>'>", (new Directives("<li class='' @class.a=\"1\">"))->compile());
        // Every Tailwind name, and -> and > in a condition
        $names = ['md:w-1/2', 'hover:underline', 'bg-[#ff0000]', 'data-[state=open]:block', '[&>*]:p-4', '!font-bold'];
        $source = '<div ' . implode(' ', array_map(static fn (string $name) => "@class.$name=\"\$a->n > 3\"", $names)) . '>';
        $this->assertSame(
            '<div class="<?php echo ' . $call('', implode(', ', array_map(static fn (string $name) => "(\$a->n > 3) ? '" . htmlspecialchars($name) . "' : ''", $names))) . ' ?>">',
            (new Directives($source))->compile(),
        );
        // A tag over several lines keeps them
        $source = "<li\n  class=\"row\"\n  @class.a=\"\$a\n    && \$b\"\n  title=\"{{ \$t }}\">\n{{ \$c }}";
        $php = (new Directives($source))->compile();
        $this->assertSame(substr_count($source, "\n"), substr_count($php, "\n"));
        $this->assertStringContainsString('$c', explode("\n", $php)[5]);
    }

    public function testConditionalClassesRenderWithoutStraySpaces(): void
    {
        $render = static function (string $source, bool $on): string {
            $file = (string) tempnam(sys_get_temp_dir(), 'live');
            file_put_contents($file, $source);
            try {
                $board = new Board();
                $board->done = $on;
                return (new Template($file, [], Board::class))->render($board, static fn () => '');
            } finally {
                unlink($file);
            }
        };
        $source = '<b class="row" @class.done="$this->done" @class.md:w-1/2="$this->done">';
        $this->assertSame('<b class="row done md:w-1/2">', $render($source, true));
        $this->assertSame('<b class="row">', $render($source, false));
        $this->assertSame('<b class="">', $render('<b @class.done="$this->done">', false));
        // With the attribute's own classes: each once, no space left over when they are empty, numbers too
        $this->assertSame('<b class="row done">', $render('<b class="row  done" @class.done="$this->done">', true));
        $this->assertSame('<b class="done">', $render('<b class="{{ \'\' }}" @class.done="$this->done">', true));
        $this->assertSame('<b class="123 x">', $render('<b @class.123="$this->done" @class.x="true">', true));
        // An error inside the attribute's value leaves no output buffer behind
        $level = ob_get_level();
        try {
            $render('<b class="{{ throw new \\LogicException(\'no\') }}" @class.done="true">', true);
            $this->fail('No exception');
        } catch (\LogicException) {
            $this->assertSame($level, ob_get_level());
        }
        // The name is a string in the PHP, the output escaped
        $this->assertSame('<b class="content-[&#039;a&amp;b&#039;] a\\b">', $render('<b @class.content-[\'a&b\']="true" @class.a\\b="1">', true));
    }

    public function testMistakesInConditionalClasses(): void
    {
        $this->assertSame(['2: @class.late belongs in an HTML start tag: <li @class.late="$order->late">'], self::problems("<p>\nlate @class.late=\"\$a\"</p>"));
        $this->assertSame(['1: @class.late belongs in an HTML start tag: <li @class.late="$order->late">'], self::problems('<p></p @class.late="$a">'));
        $this->assertSame(['1: @class.late needs a condition: @class.late="$order->late"'], self::problems('<p @class.late>'));
        $this->assertSame(['1: @class. needs a class name and a condition: @class.late="$order->late"'], self::problems('<p @class.="$a">'));
        $this->assertSame(['1: <p> has two class attributes: the browser takes the first only'], self::problems('<p class="a" class="b" @class.c="$c">'));
        $this->assertSame(['1: @class.c comes twice in <p>'], self::problems('<p @class.c="$c" @class.c="$d">'));
        // The attributes as the browser reads them: class= inside another value is none, an unquoted one needs quotes
        $this->assertSame('<p title=" class=\'t\'" class="<?php echo \\Waypoint\\classes([(1) ? \'b\' : \'\']) ?>">', (new Directives('<p title=" class=\'t\'" @class.b="1">'))->compile());
        $this->assertSame(['1: <p class=...> needs quotes for @class.: class="..."'], self::problems('<p class=a @class.b="$b">'));
        $this->assertSame(['1: <p> has two class attributes: the browser takes the first only'], self::problems('<p class="a" CLASS=b @class.c="$c">'));
        $child = new Directives('<app-row @class.c="$c"></app-row>', ['app-row']);
        $this->assertSame(['1: <app-row @class.c>: a child component takes inputs, not classes. Put the class on an element inside its template, or pass an input'], array_map(static fn (array $p) => "{$p['line']}: {$p['message']}", $child->problems));
        // A > in a {{ }}, a directive or PHP ends no tag
        $this->assertSame([], self::problems('<p title="{{ $a > 1 }}" @if ($b->c) hidden @endif <?= $d > 2 ?> @class.e="$e">'));
    }

    public function testATemplateWithProblemsDoesNotCompile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line 1: @foreach has no @endforeach');
        Template::compile('@foreach ($a as $b)', []);
    }
}
