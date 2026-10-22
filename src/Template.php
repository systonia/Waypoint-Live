<?php

namespace Waypoint\Live;

use Closure;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use Throwable;
use WeakMap;
use Waypoint\Http\View;
use Waypoint\View\Compiler;

/**
 * @internal A component's template: a PHP file rendered with `$this` as the component, private members included.
 * `<?= $this->count ?>` is HTML-escaped, `<?php echo $html ?>` stays raw; control flow works as in any view.
 * `<app-row name="<?= $city ?>" key="<?= $city ?>"></app-row>` places a child component: a value that is a
 * single `<?= expr ?>` passes the value itself (an int, an object, a closure), anything else passes a string.
 * `data-on-click="remove($item)"` binds arguments on the server: the browser only gets an ID for the call.
 * Directives (`{{ }}`, `@foreach`, `@if`: Waypoint's template syntax, see Directives) become such PHP first. Names resolve as in the
 * component's class file: the template shares its namespace and its imports.
 * Compiled once when the plugin starts; the methods its `data-on-*` attributes name are the only ones the
 * browser can call.
 */
final class Template
{
    /** @internal An attribute's value between its double quotes, `<?= ?>` in it included: a regex */
    public const VALUE = '(?:<\?=.*?\?>|[^"])*';

    private readonly string $code;

    /** @var list<string> Methods named by `data-on-*="method"` in the template. */
    public readonly array $actions;

    /** @var WeakMap<Throwable, array{string, int}>|null The template and line an error came from, for the developer */
    private static ?WeakMap $origins = null;

    /**
     * @param list<string> $selectors The app's components, expanded where their tag appears.
     * @param class-string|null $class The component, whose namespace and imports the template shares
     */
    public function __construct(private readonly string $path, array $selectors = [], ?string $class = null)
    {
        $source = is_file($path) ? file_get_contents($path) : false;
        if ($source === false) {
            throw new InvalidArgumentException("Template '$path' not found");
        }
        $actions = [];
        try {
            $this->code = self::compile($source, $selectors, $actions, $class);
        } catch (InvalidArgumentException $e) {
            $error = new InvalidArgumentException("Template '$path', " . $e->getMessage(), 0, $e);
            self::$origins ??= new WeakMap();
            self::$origins[$error] = [$path, preg_match('/^line (\d+):/', $e->getMessage(), $m) === 1 ? (int) $m[1] : 1];
            throw $error;
        }
        $this->actions = array_values(array_unique($actions));
    }

    /**
     * @internal The PHP code a template becomes; every line stays where it is in the template. It starts in PHP, with
     * the namespace and imports of $class, then $first.
     *
     * @param list<string> $selectors
     * @param list<string> $actions
     * @param-out list<string> $actions The methods its `data-on-*="method"` attributes name
     * @param class-string|null $class
     */
    public static function compile(string $source, array $selectors, array &$actions = [], ?string $class = null, string $first = ''): string
    {
        $directives = new Directives($source, $selectors);
        foreach ($directives->problems as $problem) {
            if ($problem['severity'] === 'error') {
                throw new InvalidArgumentException("line {$problem['line']}: {$problem['message']}");
            }
        }
        return self::header($class, $directives->uses) . $first . '?>' . View::escapeEchoes(self::children(self::calls($directives->compile(), $actions), $selectors));
    }

    /**
     * @internal What a template of $class starts with, all on its first line: the namespace and the imports of
     * scope().
     *
     * @param class-string|null $class
     * @param array<string, string> $uses alias => class, from `@use`
     */
    public static function header(?string $class, array $uses = []): string
    {
        [$namespace, $imports] = self::scope($class, $uses);
        return Compiler::header($namespace, $imports);
    }

    /**
     * @internal The class names a template of $class sees, for the editor: alias => class, the namespace under ''.
     *
     * @param class-string|null $class
     * @param array<string, string> $uses alias => class, from `@use`
     * @return array<string, string>
     */
    public static function names(?string $class, array $uses = []): array
    {
        [$namespace, $imports] = self::scope($class, $uses);
        $names = ['' => $namespace];
        foreach ($imports as [$kind, $name, $alias]) {
            if ($kind === 'class') {
                $names[$alias] = $name;
            }
        }
        return $names;
    }

    /**
     * The namespace and the imports of the class's file, then the template's `@use`, which wins over an import of
     * the same alias, and `classes()`. A name written from the root without a leading backslash (`App\Status` in
     * namespace `App\Shop`) still means the root's, as in a template without a namespace.
     *
     * @param class-string|null $class
     * @param array<string, string> $uses
     * @return array{string, array<string, array{string, string, string}>} The namespace, kind and alias => kind, name, alias
     */
    private static function scope(?string $class, array $uses): array
    {
        [$namespace, $imports] = $class !== null ? self::imports((string) (new ReflectionClass($class))->getFileName()) : ['', []];
        foreach ($uses as $alias => $name) {
            $imports['class ' . strtolower($alias)] = ['class', $name, $alias];
        }
        $imports['function classes'] ??= ['function', 'Waypoint\\classes', 'classes'];
        $root = strstr($namespace, '\\', true) ?: $namespace;
        if ($root !== '') {
            $imports['class ' . strtolower($root)] ??= ['class', $root, $root];
        }
        return [$namespace, $imports];
    }

    /**
     * The namespace of a PHP file and its imports up to its first class, enum, interface or trait (a trait's `use`
     * in a class body is none), one statement each, by kind and alias: PHP refuses a second import of an alias.
     *
     * @return array{string, array<string, array{string, string, string}>}
     */
    private static function imports(string $file): array
    {
        $tokens = token_get_all((string) @file_get_contents($file));
        $namespace = '';
        $imports = [];
        $previous = ';';
        for ($i = 0; isset($tokens[$i]); $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : null;
            if (in_array($id, [T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT], true) && $previous !== '::') {
                break;
            }
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            // A closure's use ($x) follows a ), an import a statement
            if ($id === T_NAMESPACE || ($id === T_USE && in_array($previous, [';', '{', '}'], true))) {
                $statement = '';
                for ($i++; isset($tokens[$i]) && $tokens[$i] !== ';' && !($id === T_NAMESPACE && $tokens[$i] === '{'); $i++) {
                    $part = $tokens[$i];
                    if (!is_array($part) || !in_array($part[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        $statement .= is_array($part) ? $part[1] : $part;
                    }
                }
                $statement = trim((string) preg_replace('/\s+/', ' ', $statement));
                if ($id === T_NAMESPACE) {
                    $namespace = $statement;
                } else {
                    $imports = [...$imports, ...self::statements($statement, $namespace)];
                }
                $previous = ';';
                continue;
            }
            $previous = is_array($token) ? $token[1] : $token;
        }
        return [$namespace, $imports];
    }

    /**
     * `use function A\{b, c as d}` as `use function A\b; use function A\c as d;`, by kind and alias.
     *
     * @return array<string, array{string, string, string}> kind and alias => kind, name, alias
     */
    private static function statements(string $statement, string $namespace): array
    {
        [$kind, $rest] = preg_match('/^(function|const) (.*)$/is', $statement, $m) === 1 ? [strtolower($m[1]), $m[2]] : ['', $statement];
        [$prefix, $items] = preg_match('/^(.*?)\s*\{(.*)\}$/s', $rest, $m) === 1 ? [trim($m[1], '\\ ') . '\\', explode(',', $m[2])] : ['', explode(',', $rest)];
        $imports = [];
        foreach ($items as $item) {
            if (preg_match('/^\s*(?:(function|const)\s+)?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*$/i', $item, $m) !== 1) {
                continue;
            }
            $itemKind = $m[1] !== '' ? strtolower($m[1]) : $kind;
            $name = ltrim($prefix . $m[2], '\\');
            $last = substr((string) strrchr('\\' . $name, '\\'), 1);
            $alias = ($m[3] ?? '') !== '' ? $m[3] : $last;
            // Outside a namespace PHP warns that `use Closure;` does nothing
            if ($namespace === '' && !str_contains($name, '\\') && $alias === $name) {
                continue;
            }
            $key = ($itemKind !== '' ? $itemKind : 'class') . ' ' . ($itemKind === 'const' ? $alias : strtolower($alias));
            $imports[$key] = [$itemKind !== '' ? $itemKind : 'class', $name, $alias];
        }
        return $imports;
    }

    /**
     * @param Closure(string, array<string, mixed>): string $child Renders a child component's tag.
     * @param (Closure(string, list<mixed>): string)|null $action Keeps a method and its arguments, returns the ID the browser calls it by.
     */
    public function render(object $component, Closure $child, ?Closure $action = null): string
    {
        $action ??= static fn (string $method): never => throw new LogicException("data-on-*=\"$method(...)\" needs a circuit to render in");
        $level = ob_get_level();
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
            // A class attribute with @class. buffers its value: an error inside leaves that buffer open
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
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
        $value = '"(' . self::VALUE . ')"';
        $tag = '/<(' . implode('|', array_map(static fn (string $s) => preg_quote($s, '/'), $selectors)) . ')((?:\s+[A-Za-z_][\w.:-]*(?:="' . self::VALUE . '")?)*)\s*(?:\/>|>\s*<\/\1>)(\r\n|\n|\r|)/s';
        return (string) preg_replace_callback($tag, static function (array $m) use ($value): string {
            preg_match_all('/([A-Za-z_][\w.:-]*)(?:=' . $value . ')?/s', $m[2], $attributes, PREG_SET_ORDER);
            $inputs = [];
            foreach ($attributes as $attribute) {
                $inputs[] = var_export($attribute[1], true) . ' => ' . self::value($attribute[2] ?? null);
            }
            $code = '<?php echo $__child(' . var_export($m[1], true) . ', [' . implode(', ', $inputs) . '])' . ($m[3] !== '' ? ', ' . json_encode($m[3]) : '') . ';';
            // A tag over several lines keeps them, as blank lines in the PHP: the lines after it stay where they are
            $code .= str_repeat("\n", max(0, substr_count($m[0], "\n") - substr_count($m[3], "\n") - substr_count($code, "\n")));
            // PHP swallows a newline after a closing tag: echo it instead, so the output and the line numbers stay put
            return $code . ' ?>' . $m[3];
        }, $source);
    }

    /**
     * @internal The `data-on-*` attributes of a template's PHP (Directives::compile()) outside comments, with their
     * lines: what the browser can reach, for the checks.
     *
     * @return list<array{event: string, value: string, line: int, offset: int}>
     */
    public static function wiring(string $php): array
    {
        $wired = [];
        self::calls($php, $actions, $wired);
        return $wired;
    }

    /**
     * `data-on-click="remove($item)"` becomes `data-on-click="<?= $__action('remove', [$item]) ?>"`, and
     * `data-on-click="save"` names an action, in the HTML only. Markup in a comment does neither: a button that
     * was commented out leaves its method out of reach and its arguments unevaluated.
     *
     * @param list<string>|null $actions
     * @param-out list<string> $actions
     * @param list<array{event: string, value: string, line: int, offset: int}> $wired
     * @param-out list<array{event: string, value: string, line: int, offset: int}> $wired Every data-on-* attribute, by line and offset in $source
     */
    private static function calls(string $source, ?array &$actions, array &$wired = []): string
    {
        $actions ??= [];
        $code = '';
        $comment = false;
        $at = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (!is_array($token) || $token[0] !== T_INLINE_HTML) {
                $code .= $text;
                $at += strlen($text);
                continue;
            }
            // An HTML comment may span PHP tags: whether one is open carries over to the next piece of HTML
            foreach ((array) preg_split('/(<!--|-->)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                $part = (string) $part;
                $from = $at;
                $at += strlen($part);
                if ($comment || $part === '<!--') {
                    $comment = $part !== '-->';
                    $code .= $part;
                    continue;
                }
                preg_match_all('/\bdata-on-([a-z]+)="([^"]*)"/', $part, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
                foreach ($found as [[, $offset], [$event], [$value]]) {
                    $wired[] = ['event' => $event, 'value' => $value, 'line' => substr_count($code, "\n") + substr_count($part, "\n", 0, $offset) + 1, 'offset' => $from + $offset];
                    if (preg_match('/^[A-Za-z_]\w*$/', $value) === 1) {
                        $actions[] = $value;
                    }
                }
                $code .= preg_replace('/\bdata-on-([a-z]+)="([A-Za-z_]\w*)\(([^"]*)\)"/', 'data-on-$1="<?= $__action(\'$2\', [$3]) ?>"', $part);
            }
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
            $part = $i % 2 === 1 ? (string) preg_replace('/,\s*"(?:\\\\r\\\\n|\\\\n|\\\\r)"\s*$/', '', (string) $part) : $part;
            $code[] = $i % 2 === 1 ? '(string) (' . $part . ')' : var_export(html_entity_decode((string) $part, ENT_QUOTES | ENT_HTML5), true);
        }
        return implode(' . ', $code);
    }
}
