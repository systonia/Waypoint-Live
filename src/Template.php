<?php

namespace Waypoint\Live;

use Closure;
use InvalidArgumentException;
use LogicException;
use Stringable;
use Throwable;
use TypeError;
use WeakMap;

/**
 * A component's template: a PHP file rendered with `$this` as the component, private members included.
 * `<?= $this->count ?>` is HTML-escaped, `<?php echo $html ?>` stays raw; control flow works as in any view.
 * `<app-row name="<?= $city ?>" key="<?= $city ?>"></app-row>` places a child component: a value that is a
 * single `<?= expr ?>` passes the value itself (an int, an object, a closure), anything else passes a string.
 * `data-on-click="remove($item)"` binds arguments on the server: the browser only gets an ID for the call.
 * Directives (`{{ }}`, `@foreach`, `@if`, see Directives) become such PHP first.
 * Compiled once when the plugin starts; the methods its `data-on-*` attributes name are the only ones the
 * browser can call.
 */
final class Template
{
    private readonly string $code;

    /** @var list<string> Methods named by `data-on-*="method"` in the template. */
    public readonly array $actions;

    /** @var WeakMap<Throwable, array{string, int}>|null The template and line an error came from, for the developer */
    private static ?WeakMap $origins = null;

    /** @param list<string> $selectors The app's components, expanded where their tag appears. */
    public function __construct(private readonly string $path, array $selectors = [])
    {
        $source = is_file($path) ? file_get_contents($path) : false;
        if ($source === false) {
            throw new InvalidArgumentException("Template '$path' not found");
        }
        preg_match_all('/\bdata-on-[a-z]+="([A-Za-z_]\w*)"/', $source, $named);
        $this->actions = array_values(array_unique($named[1]));
        try {
            $this->code = self::compile($source, $selectors);
        } catch (InvalidArgumentException $e) {
            $error = new InvalidArgumentException("Template '$path', " . $e->getMessage(), 0, $e);
            self::$origins ??= new WeakMap();
            self::$origins[$error] = [$path, preg_match('/^line (\d+):/', $e->getMessage(), $m) === 1 ? (int) $m[1] : 1];
            throw $error;
        }
    }

    /**
     * @internal The PHP code a template becomes; every line stays where it is in the template.
     *
     * @param list<string> $selectors
     */
    public static function compile(string $source, array $selectors): string
    {
        $directives = new Directives($source);
        foreach ($directives->problems as $problem) {
            if ($problem['severity'] === 'error') {
                throw new InvalidArgumentException("line {$problem['line']}: {$problem['message']}");
            }
        }
        return '?>' . self::escapeEchoes(self::children(self::calls($directives->compile()), $selectors));
    }

    /**
     * @param Closure(string, array<string, mixed>): string $child Renders a child component's tag.
     * @param (Closure(string, list<mixed>): string)|null $action Keeps a method and its arguments, returns the ID the browser calls it by.
     */
    public function render(object $component, Closure $child, ?Closure $action = null): string
    {
        $action ??= static fn (string $method): never => throw new LogicException("data-on-*=\"$method(...)\" needs a circuit to render in");
        ob_start();
        try {
            // ponytail: eval on every render; a compiled file in a cache directory lets opcache keep it once renders get hot
            (function (string $__code, Closure $__child, Closure $__action): void {
                eval($__code);
            })->call($component, $this->code, $child, $action);
            return (string) ob_get_contents();
        } catch (Throwable $e) {
            // The innermost template keeps it: a child's template renders inside its parent's
            self::$origins ??= new WeakMap();
            $line = self::evalLine($e);
            if (!isset(self::$origins[$e]) && $line !== null) {
                self::$origins[$e] = [$this->path, $line];
            }
            throw $e;
        } finally {
            ob_end_clean();
        }
    }

    /**
     * @internal The template file and line an error of a render came from: where it was thrown, or where the
     * template called what threw it. The compiler keeps the lines, so they are the template's.
     *
     * @return array{string, int}|null
     */
    public static function origin(Throwable $e): ?array
    {
        return self::$origins[$e] ?? null;
    }

    private static function evalLine(Throwable $e): ?int
    {
        if (str_ends_with($e->getFile(), "eval()'d code")) {
            return $e->getLine();
        }
        foreach ($e->getTrace() as $frame) {
            if (str_ends_with($frame['file'] ?? '', "eval()'d code")) {
                return $frame['line'] ?? null;
            }
        }
        return null;
    }

    /** @internal What a compiled `<?= ?>` calls: everything escaped, except Html. */
    public static function escape(mixed ...$values): string
    {
        $html = '';
        foreach ($values as $value) {
            if (!is_scalar($value) && $value !== null && !$value instanceof Stringable) {
                throw new TypeError('A template can only print scalars and Stringable, not ' . get_debug_type($value));
            }
            $html .= $value instanceof Html ? (string) $value : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE);
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
            $code = '<?php echo $__child(' . var_export($m[1], true) . ', [' . implode(', ', $inputs) . '])' . ($m[3] !== '' ? ', "\n"' : '') . ';';
            // A tag over several lines keeps them, as blank lines in the PHP: the lines after it stay where they are
            $code .= str_repeat("\n", max(0, substr_count($m[0], "\n") - strlen($m[3]) - substr_count($code, "\n")));
            // PHP swallows a newline after a closing tag: echo it instead, so the output and the line numbers stay put
            return $code . ' ?>' . $m[3];
        }, $source);
    }

    /** `data-on-click="remove($item)"` becomes `data-on-click="<?= $__action('remove', [$item]) ?>"`, in the HTML only. */
    private static function calls(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $code .= is_array($token) && $token[0] === T_INLINE_HTML
                ? preg_replace('/\bdata-on-([a-z]+)="([A-Za-z_]\w*)\(([^"]*)\)"/', 'data-on-$1="<?= $__action(\'$2\', [$3]) ?>"', $text)
                : $text;
        }
        return $code;
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
            // A {{ }} at the end of a line echoes the newline PHP would swallow (Directives); inside a value the
            // newline stays in the text after it, so the echo of it goes
            $part = $i % 2 === 1 ? (string) preg_replace('/,\s*"\\\\n"\s*$/', '', (string) $part) : $part;
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
