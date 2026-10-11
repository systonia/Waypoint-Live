<?php

namespace Waypoint\Live;

use Closure;
use InvalidArgumentException;
use Stringable;
use TypeError;

/**
 * A component's template: a PHP file rendered with `$this` as the component, private members included.
 * `<?= $this->count ?>` is HTML-escaped, `<?php echo $html ?>` stays raw; control flow works as in any view.
 * `<app-row name="<?= $city ?>" key="<?= $city ?>"></app-row>` places a child component: a value that is a
 * single `<?= expr ?>` passes the value itself (an int, an object, a closure), anything else passes a string.
 * `<?php /** @var \App\Counter $this *\/ ?>` on the first line lets the IDE follow `$this` into the component.
 * Compiled once when the plugin starts; the methods its `data-on-*` attributes name are the only ones the
 * browser can call.
 */
final class Template
{
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
        preg_match_all('/\bdata-on-[a-z]+="([A-Za-z_]\w*)"/', $source, $named);
        $this->actions = array_values(array_unique($named[1]));
        $this->code = '?>' . self::escapeEchoes(self::children($source, $selectors));
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

    /** @internal What a compiled `<?= ?>` calls: everything escaped, except Html. */
    public static function escape(mixed ...$values): string
    {
        $html = '';
        foreach ($values as $value) {
            if (!is_scalar($value) && $value !== null && !$value instanceof Stringable) {
                throw new TypeError('A template can only print scalars and Stringable, not ' . get_debug_type($value));
            }
            $html .= $value instanceof Html ? (string) $value : htmlspecialchars((string) $value, ENT_QUOTES);
        }
        return $html;
    }

    /**
     * Child tags become `<?php echo $__child(...) ?>`: raw, since the child's template escapes its own output.
     *
     * @param list<string> $selectors
     */
    private static function children(string $source, array $selectors): string
    {
        if ($selectors === []) {
            return $source;
        }
        $value = '"((?:<\?=.*?\?>|[^"])*)"';
        $tag = '/<(' . implode('|', array_map(static fn (string $s) => preg_quote($s, '/'), $selectors)) . ')((?:\s+[A-Za-z_][\w.:-]*(?:="(?:<\?=.*?\?>|[^"])*")?)*)\s*(?:\/>|>\s*<\/\1>)(\n?)/s';
        return (string) preg_replace_callback($tag, static function (array $m) use ($value): string {
            preg_match_all('/([A-Za-z_][\w.:-]*)(?:=' . $value . ')?/s', $m[2], $attributes, PREG_SET_ORDER);
            $inputs = [];
            foreach ($attributes as $attribute) {
                $inputs[] = var_export($attribute[1], true) . ' => ' . self::value($attribute[2] ?? null);
            }
            // PHP swallows a newline after a closing tag: echo it instead, so the output and the line numbers stay put
            return '<?php echo $__child(' . var_export($m[1], true) . ', [' . implode(', ', $inputs) . '])' . ($m[3] !== '' ? ', "\n"' : '') . '; ?>' . $m[3];
        }, $source);
    }

    /** An attribute's value as PHP code: a lone `<?= expr ?>` is the value itself, anything else a string. */
    private static function value(?string $value): string
    {
        if ($value === null) {
            return 'true';
        }
        if (preg_match('/^<\?=(.*?);?\s*\?>$/s', $value, $m) === 1 && !str_contains($m[1], '?>')) {
            return '(' . $m[1] . ')';
        }
        $parts = (array) preg_split('/<\?=(.*?);?\s*\?>/s', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $code = [];
        foreach ($parts as $i => $part) {
            $code[] = $i % 2 === 1 ? '(string) (' . $part . ')' : var_export(html_entity_decode((string) $part, ENT_QUOTES | ENT_HTML5), true);
        }
        return implode(' . ', $code);
    }

    /** `<?= a, b ?>` becomes `<?= \Waypoint\Live\Template::escape(a, b) ?>`. */
    private static function escapeEchoes(string $source): string
    {
        $code = '';
        $open = false;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_OPEN_TAG_WITH_ECHO) {
                $code .= $text . '\\' . self::class . '::escape(';
                $open = true;
            } elseif ($open && is_array($token) && $token[0] === T_CLOSE_TAG) {
                $code = rtrim(rtrim($code), ';') . ')' . $text;
                $open = false;
            } else {
                $code .= $text;
            }
        }
        return $open ? rtrim(rtrim($code), ';') . ')' : $code;
    }
}
