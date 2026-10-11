<?php

namespace Waypoint\Live;

use Closure;
use InvalidArgumentException;

/**
 * A component's template: a PHP file rendered with `$this` as the component, private members included.
 * `{$this->count}` in the HTML is escaped; `<?php foreach ... ?>` works as in any view, `<?= ?>` stays raw.
 * `<app-row name="{$city}" key="{$city}"></app-row>` places a child component: a value that is a single
 * `{$expr}` passes the value itself (an int, an object, a closure), anything else passes a string.
 * Compiled once when the plugin starts; the methods its `data-on-*` attributes name are the only ones the
 * browser can call.
 */
final class Template
{
    private const EXPRESSION = '\{(\$[^{}]+)\}';

    private readonly string $code;

    /** @var list<string> Methods named by `data-on-*="method"` in the template. */
    public readonly array $actions;

    /** @param list<string> $selectors The app's components, expanded where their tag appears. */
    public function __construct(string $path, array $selectors = [])
    {
        $source = is_file($path) ? file_get_contents($path) : false;
        if ($source === false) {
            throw new InvalidArgumentException("Template '$path' not found");
        }
        $code = '';
        $actions = [];
        foreach (token_get_all($source) as $token) {
            // Only the HTML between PHP blocks: "{$x}" inside PHP code is a string interpolation
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                preg_match_all('/\bdata-on-[a-z]+="([A-Za-z_]\w*)"/', $token[1], $named);
                array_push($actions, ...$named[1]);
                $token = self::compile($token[1], $selectors);
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $this->code = '?>' . $code;
        $this->actions = array_values(array_unique($actions));
    }

    /** @param Closure(string, array<string, mixed>): string $child Renders a child component's tag. */
    public function render(object $component, Closure $child): string
    {
        ob_start();
        try {
            // ponytail: eval on every render; a compiled file in a cache directory lets opcache keep it once renders get hot
            (function (string $__code, Closure $__child): void {
                eval($__code);
            })->call($component, $this->code, $child);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** @param list<string> $selectors */
    private static function compile(string $html, array $selectors): string
    {
        // Child tags first, parked behind markers so the {$...} pass below leaves their code alone
        $children = [];
        if ($selectors !== []) {
            $tag = '/<(' . implode('|', array_map(static fn (string $s) => preg_quote($s, '/'), $selectors)) . ')((?:\s+[A-Za-z_][\w.:-]*(?:="[^"]*")?)*)\s*(?:\/>|>\s*<\/\1>)(\n?)/';
            $html = (string) preg_replace_callback($tag, static function (array $m) use (&$children): string {
                preg_match_all('/([A-Za-z_][\w.:-]*)(?:="([^"]*)")?/', $m[2], $attributes, PREG_SET_ORDER);
                $inputs = [];
                foreach ($attributes as $attribute) {
                    $inputs[] = var_export($attribute[1], true) . ' => ' . self::value($attribute[2] ?? null);
                }
                $children[] = self::echo('$__child(' . var_export($m[1], true) . ', [' . implode(', ', $inputs) . '])', $m[3]);
                return "\0" . (count($children) - 1) . "\0";
            }, $html);
        }
        $html = (string) preg_replace_callback(
            '/' . self::EXPRESSION . '(\n?)/',
            static fn (array $m) => self::echo('\htmlspecialchars((string) (' . $m[1] . '), \ENT_QUOTES)', $m[2]),
            $html,
        );
        return (string) preg_replace_callback('/\0(\d+)\0/', static fn (array $m) => $children[(int) $m[1]], $html);
    }

    /** PHP swallows a newline after a closing tag: echo it instead, so lines (and error line numbers) stay put. */
    private static function echo(string $expression, string $newline): string
    {
        return '<?= ' . $expression . ($newline !== '' ? ' . "\n"' : '') . ' ?>' . $newline;
    }

    /** An attribute's value as PHP code: a lone {$expr} is the value itself, anything else a string. */
    private static function value(?string $value): string
    {
        if ($value === null) {
            return 'true';
        }
        if (preg_match('/^' . self::EXPRESSION . '$/', $value, $m) === 1) {
            return '(' . $m[1] . ')';
        }
        $parts = (array) preg_split('/' . self::EXPRESSION . '/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $code = [];
        foreach ($parts as $i => $part) {
            $code[] = $i % 2 === 1 ? '(string) (' . $part . ')' : var_export(html_entity_decode((string) $part, ENT_QUOTES | ENT_HTML5), true);
        }
        return implode(' . ', $code);
    }
}
