<?php

namespace Waypoint\Live;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Tenma\Lsp\Provider;
use Throwable;

/**
 * Live in the editor, through `tenma lsp` (composer.json names it under extra.tenma.lsp): `$this` in a
 * template is its component, `data-on-click="` completes the component's public methods, `<app-` the
 * selectors, a child tag its #[Input] properties. F12 leads from a tag to its class and from an action
 * to its method. Problems shows what Problems finds while the template is typed.
 *
 * The components are the classes with #[Component] below the project's autoload and autoload-dev
 * directories (PSR-4 and classmap), not only those listed in a LivePlugin: the editor runs no app.
 */
final class Editor implements Provider
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
        if ($class !== null && preg_match_all('/\bdata-on-[a-z]+="(\w+)"/', $text, $actions, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
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
        if ($this->components !== null) {
            return $this->components;
        }
        $components = new Components();
        foreach ($this->classes() as $class) {
            try {
                $components->add($class);
            } catch (Throwable) {
                // A component that cannot be added (no template, a taken selector) gets no help; the app reports it at boot
            }
        }
        return $this->components = $components->sources();
    }

    /** @return list<class-string> Classes with #[Component] in the project's autoloaded directories */
    private function classes(): array
    {
        $composer = json_decode((string) @file_get_contents("$this->root/composer.json"), true);
        $directories = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = is_array($composer) && is_array($composer[$section] ?? null) ? $composer[$section] : [];
            foreach (['psr-4', 'classmap'] as $kind) {
                // psr-4 maps a prefix to one directory or several, classmap lists them
                foreach ((array) ($autoload[$kind] ?? []) as $paths) {
                    foreach ((array) $paths as $path) {
                        if (is_string($path)) {
                            $directories[] = "$this->root/" . trim($path, '/');
                        }
                    }
                }
            }
        }
        $classes = [];
        foreach (array_unique($directories) as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = $file instanceof SplFileInfo ? $file->getPathname() : '';
                if (!str_ends_with($path, '.php') || str_contains($path, '/vendor/')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                $class = str_contains($source, 'Component') ? self::declaredClass($source) : null;
                if ($class !== null && class_exists($class) && (new ReflectionClass($class))->getAttributes(Component::class) !== []) {
                    $classes[] = $class;
                }
            }
        }
        return $classes;
    }

    /** The first class a PHP file declares, with its namespace. */
    private static function declaredClass(string $source): ?string
    {
        $namespace = '';
        $tokens = token_get_all($source);
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $name = $tokens[$i + 2] ?? null;
                $namespace = is_array($name) ? $name[1] . '\\' : '';
            }
            // `Foo::class` is no declaration
            $previous = $tokens[$i - 1] ?? null;
            if ($token[0] === T_CLASS && !(is_array($previous) && $previous[0] === T_DOUBLE_COLON)) {
                $name = $tokens[$i + 2] ?? null;
                return is_array($name) && $name[0] === T_STRING ? $namespace . $name[1] : null;
            }
        }
        return null;
    }
}
