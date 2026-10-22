<?php

namespace Waypoint\Live;

use ReflectionClass;
use ReflectionMethod;
use Lsp\{Imports, Provider, Tokens};

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
            ], LivePlugin::actions($class));
        }
        if (preg_match('/<[a-z][a-z0-9-]*$/', $before) === 1) {
            $items = [];
            foreach ($this->components() as $selector => [$component]) {
                $items[] = ['label' => $selector, 'kind' => 'class', 'detail' => $component];
            }
            return $items;
        }
        // At an attribute's name inside a component's tag: its inputs that are not there yet
        $value = '"' . Template::VALUE . '"';
        if (preg_match('/<([a-z][a-z0-9]*(?:-[a-z0-9]+)+)((?:\s+[\w.:-]+(?:=' . $value . ')?)*)\s+[\w.:-]*$/s', $before, $m) === 1) {
            $component = $this->components()[$m[1]][0] ?? null;
            if ($component === null) {
                return [];
            }
            preg_match_all('/([\w.:-]+)(?:=' . $value . ')?/s', $m[2], $present);
            $items = [];
            foreach ([...LivePlugin::inputs($component), ...($class !== null ? ['key'] : [])] as $name) {
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
        $tokens = \Waypoint\View\Editor::highlight(new Directives($text), $text);
        // data-on-click="remove($item)": the method and its arguments, which are PHP
        preg_match_all('/\bdata-on-[a-z]+="(\w+)\(([^"]*)\)"/', $text, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($calls as [, [$name, $start], [$args, $from]]) {
            \Waypoint\View\Editor::token($tokens, $text, $start, $start + strlen($name), 'method');
            \Waypoint\View\Editor::php($tokens, $text, $from, $from + strlen($args));
        }
        return $tokens;
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

    /** @return array<string, array{class-string, string}> */
    private function components(): array
    {
        return $this->components ??= Components::scan($this->root)->sources();
    }
}
