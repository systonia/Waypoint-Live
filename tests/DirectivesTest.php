<?php

namespace Waypoint\Live\Tests;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Waypoint\Live\{Directives, Template};
use Waypoint\Live\Tests\Fixtures\Shelf;

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
            "<ul>\n    <li data-on-click=\"pick\">Dune</li>\n    <li data-on-click=\"pick\">&lt;Emma&gt;</li>\n</ul>\n<test-item>\n<hr>\nmail@example.com @if\n",
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

    public function testATemplateWithProblemsDoesNotCompile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line 1: @foreach has no @endforeach');
        Template::compile('@foreach ($a as $b)', []);
    }
}
