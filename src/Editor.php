<?php

namespace Waypoint\Live;

use ReflectionClass;
use ReflectionMethod;
use Tenma\Lsp\{Imports, Provider, Tokens};

/**
 * @internal Live in the editor, through `tenma lsp` (composer.json names it under extra.tenma.lsp): `$this` in a
 * template is its component, `data-on-click="` completes the component's public methods, `<app-` the
 * selectors, a child tag its #[Input] properties. F12 leads from a tag to its class and from an action
 * to its method. Class names resolve as in the compiled template (Template::names()). Problems shows what
 * Problems finds while the template is typed, and directives get their colours: the editor's PHP grammar sees
 * them as text.
 *
 * The components are the classes with #[Component] below the project's autoload and autoload-dev
 * directories (PSR-4 and classmap), not only those listed in a LivePlugin: the editor runs no app.
 *
 * @phpstan-type TokenType 'keyword'|'comment'|'variable'|'property'|'method'|'function'|'class'|'string'|'number'|'operator'
 * @phpstan-type Token array{line: int, column: int, length: int, type: TokenType}
 */
final class Editor implements Provider, Tokens, Imports
{
    /** @var array<string, array{class-string, string}>|null selector => class, template file */
    private ?array $components = null;

    public function __construct(private readonly string $root)
    {
    }

    public function thisClass(string $file): ?string
    {
        return $this->templateOf($file);
    }

    public function completions(string $file, string $text, int $offset): array
    {
        $before = substr($text, 0, $offset);
        $class = $this->templateOf($file);
        if ($class !== null && preg_match('/\bdata-on-[a-z]+="\w*$/', $before) === 1) {
            return array_map(static fn (ReflectionMethod $method) => [
                'label' => $method->getName(),
                'kind' => 'method',
                'detail' => $method->getDeclaringClass()->getShortName() . '::' . $method->getName() . '()',
            ], self::actions($class));
        }
        if (preg_match('/<[a-z][a-z0-9-]*$/', $before) === 1) {
            $items = [];
            foreach ($this->components() as $selector => [$component]) {
                $items[] = ['label' => $selector, 'kind' => 'class', 'detail' => $component];
            }
            return $items;
        }
        // At an attribute's name inside a component's tag: its inputs that are not there yet
        if (preg_match('/<([a-z][a-z0-9]*(?:-[a-z0-9]+)+)((?:\s+[\w.:-]+(?:="(?:<\?=.*?\?>|[^"])*")?)*)\s+[\w.:-]*$/s', $before, $m) === 1) {
            $component = $this->components()[$m[1]][0] ?? null;
            if ($component === null) {
                return [];
            }
            preg_match_all('/([\w.:-]+)(?:="(?:<\?=.*?\?>|[^"])*")?/s', $m[2], $present);
            $items = [];
            foreach ([...self::inputs($component), ...($class !== null ? ['key'] : [])] as $name) {
                if (!in_array($name, $present[1], true)) {
                    $items[] = ['label' => $name, 'kind' => 'property', 'detail' => $name === 'key' ? 'keeps the child across renders' : (string) (new \ReflectionProperty($component, $name))->getType(), 'insert' => "$name=\""];
                }
            }
            return $items;
        }
        return [];
    }

    public function definition(string $file, string $text, int $offset): ?array
    {
        // A tag's name: its class
        if (preg_match_all('/<\/?([a-z][a-z0-9]*(?:-[a-z0-9]+)+)/', $text, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($tags as [, [$selector, $start]]) {
                $component = $this->components()[$selector][0] ?? null;
                if ($component !== null && $offset >= $start && $offset <= $start + strlen($selector)) {
                    $class = new ReflectionClass($component);
                    return ['file' => (string) $class->getFileName(), 'line' => (int) $class->getStartLine()];
                }
            }
        }
        // An action in a template: its method
        $class = $this->templateOf($file);
        if ($class !== null && preg_match_all('/\bdata-on-[a-z]+="(\w+)["(]/', $text, $actions, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($actions as [, [$name, $start]]) {
                if ($offset >= $start && $offset <= $start + strlen($name) && method_exists($class, $name)) {
                    $method = new ReflectionMethod($class, $name);
                    return ['file' => (string) $method->getFileName(), 'line' => (int) $method->getStartLine()];
                }
            }
        }
        return null;
    }

    public function diagnostics(string $file, string $text): array
    {
        $class = $this->templateOf($file);
        if ($class === null) {
            return [];
        }
        return Problems::inTemplate($text, $class, array_map(static fn (array $component) => $component[0], $this->components()));
    }

    public function imports(string $file, string $text): array
    {
        $class = $this->templateOf($file);
        return $class === null ? [] : Template::names($class, (new Directives($text))->uses);
    }

    /** @return list<Token> */
    public function tokens(string $file, string $text): array
    {
        if ($this->templateOf($file) === null) {
            return [];
        }
        $directives = new Directives($text);
        $tokens = [];
        foreach ($directives->found as $found) {
            if ($found['kind'] === 'at' || $found['kind'] === 'insert') {
                continue;
            }
            if ($found['kind'] === 'class') {
                self::token($tokens, $text, $found['start'], $found['start'] + 6, 'keyword');
                self::token($tokens, $text, $found['start'] + 7, $found['start'] + 7 + strlen($found['class'] ?? ''), 'string');
                self::php($tokens, $text, ...($found['expression'] ?? [0, 0]));
                continue;
            }
            if ($found['kind'] === 'comment' || $found['kind'] === 'broken') {
                self::token($tokens, $text, $found['start'], $found['end'], $found['kind'] === 'comment' ? 'comment' : 'keyword');
                continue;
            }
            [$from, $to] = $found['expression'] ?? [$found['end'], $found['end']];
            if ($found['kind'] === 'directive' || $found['kind'] === 'use') {
                self::token($tokens, $text, $found['start'], $found['start'] + 1 + strlen($found['name'] ?? ''), 'keyword');
            } else {
                // {{ and }}, @code and @endcode
                self::token($tokens, $text, $found['start'], $from, 'keyword');
                self::token($tokens, $text, $to, $found['end'], 'keyword');
            }
            self::php($tokens, $text, $from, $to);
        }
        // data-on-click="remove($item)": the method and its arguments, which are PHP
        preg_match_all('/\bdata-on-[a-z]+="(\w+)\(([^"]*)\)"/', $text, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($calls as [, [$name, $start], [$args, $from]]) {
            self::token($tokens, $text, $start, $start + strlen($name), 'method');
            self::php($tokens, $text, $from, $from + strlen($args));
        }
        return $tokens;
    }

    /**
     * PHP's own tokens of an expression: variables, members, calls, classes, strings, numbers, keywords.
     *
     * @param list<Token> $tokens
     */
    private static function php(array &$tokens, string $text, int $from, int $to): void
    {
        $php = token_get_all('<?php ' . substr($text, $from, $to - $from));
        $at = $from - 6;
        foreach ($php as $i => $token) {
            $length = strlen(is_array($token) ? $token[1] : $token);
            if (is_array($token)) {
                $type = match ($token[0]) {
                    T_VARIABLE => 'variable',
                    T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE => 'string',
                    T_LNUMBER, T_DNUMBER => 'number',
                    T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED => 'class',
                    T_STRING => self::name($php, $i),
                    T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT => null,
                    // as, instanceof, fn, match, new, ...: every other word
                    default => ctype_alpha($token[1]) ? 'keyword' : 'operator',
                };
                if ($type !== null) {
                    self::token($tokens, $text, $at, $at + $length, $type);
                }
            }
            $at += $length;
        }
    }

    /**
     * What a bare name is by what surrounds it: `->name(` a method, `->name` a property, `name(` a function.
     *
     * @param list<string|array{int, string, int}> $php
     * @return 'method'|'property'|'keyword'|'function'|'class'
     */
    private static function name(array $php, int $i): string
    {
        $neighbour = static function (int $step) use ($php, $i): string {
            for ($j = $i + $step; isset($php[$j]); $j += $step) {
                if (!is_array($php[$j]) || $php[$j][0] !== T_WHITESPACE) {
                    return is_array($php[$j]) ? $php[$j][1] : $php[$j];
                }
            }
            return '';
        };
        $call = $neighbour(1) === '(';
        return match (true) {
            in_array($neighbour(-1), ['->', '?->', '::'], true) => $call ? 'method' : 'property',
            in_array(strtolower(is_array($php[$i]) ? $php[$i][1] : ''), ['true', 'false', 'null'], true) => 'keyword',
            $call => 'function',
            default => 'class',
        };
    }

    /**
     * One token per line it covers: editors take no token across lines.
     *
     * @param list<Token> $tokens
     * @param TokenType $type
     */
    private static function token(array &$tokens, string $text, int $start, int $end, string $type): void
    {
        while ($start < $end) {
            $newlineBefore = strrpos(substr($text, 0, $start), "\n");
            $lineStart = $newlineBefore === false ? 0 : $newlineBefore + 1;
            $newline = strpos($text, "\n", $start);
            $stop = $newline === false ? $end : min($end, $newline);
            if ($stop > $start) {
                $tokens[] = ['line' => substr_count($text, "\n", 0, $start) + 1, 'column' => $start - $lineStart, 'length' => $stop - $start, 'type' => $type];
            }
            $start = $stop + 1;
        }
    }

    /** @return class-string|null The component whose template $file is */
    private function templateOf(string $file): ?string
    {
        $file = realpath($file) ?: $file;
        foreach ($this->components() as [$class, $template]) {
            if ((realpath($template) ?: $template) === $file) {
                return $class;
            }
        }
        return null;
    }

    /**
     * @param class-string $class
     * @return list<ReflectionMethod> What `data-on-*` may name: public, not static, no magic
     */
    private static function actions(string $class): array
    {
        return array_values(array_filter(
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method) => !$method->isStatic() && !str_starts_with($method->getName(), '__'),
        ));
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private static function inputs(string $class): array
    {
        $names = array_map(static fn (\ReflectionProperty $p) => $p->getName(), (new ReflectionClass($class))->getProperties());
        return array_values(array_filter($names, static fn (string $name) => LivePlugin::isInput($class, $name)));
    }

    /** @return array<string, array{class-string, string}> */
    private function components(): array
    {
        return $this->components ??= Components::scan($this->root)->sources();
    }
}
