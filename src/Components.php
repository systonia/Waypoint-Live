<?php

namespace Waypoint\Live;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * The app's live components: those given to LivePlugin, and those another plugin adds in its boot(), which takes
 * this service as a parameter: `boot(Components $components)`, then `$components->add(StampCard::class)`. Only
 * these can be created; the browser names them by selector. The templates are compiled on first use, once every
 * selector is known.
 */
final class Components
{
    /** @var array<string, array{class-string, string}> selector => class, template file */
    private array $found = [];

    /** @var array<string, array{class-string, Template}>|null */
    private ?array $compiled = null;

    /** @var array<string, string> selector => hash of the template file and its class's imports it was compiled from */
    private array $hashes = [];

    /**
     * Adds components, at boot: a class without #[Component], a selector that is taken or no custom element name,
     * or a missing template is refused at once.
     *
     * @param class-string ...$classes Classes with a #[Component] attribute
     * @throws \InvalidArgumentException for a class that cannot be a component
     * @throws \LogicException once a page has used the components
     */
    public function add(string ...$classes): self
    {
        if ($this->compiled !== null) {
            throw new LogicException('Components are added at boot, before the first page uses them');
        }
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $attribute = ($reflection->getAttributes(Component::class)[0] ?? null)?->newInstance()
                ?? throw new InvalidArgumentException("$class has no #[Component] attribute");
            $selector = $attribute->selectorOf($class);
            // A custom element name: it becomes the tag around the component in the page
            if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $selector) !== 1 || isset($this->found[$selector])) {
                throw new InvalidArgumentException("$class: selector '$selector' must be unique, lowercase and contain a dash");
            }
            $template = dirname((string) $reflection->getFileName()) . '/' . $attribute->templateOf($class);
            // Missing at boot, not on the first page that would show it
            if (!is_file($template)) {
                throw new InvalidArgumentException("$class: template $template not found");
            }
            $this->found[$selector] = [$class, $template];
        }
        return $this;
    }

    /**
     * @internal
     *
     * @return array{class-string, Template}|null
     */
    public function get(string $selector): ?array
    {
        return $this->compile()[$selector] ?? null;
    }

    /**
     * @internal For the editor: every component with its template file, not compiled.
     *
     * @return array<string, array{class-string, string}> selector => class, template file
     */
    public function sources(): array
    {
        return $this->found;
    }

    /** @return list<string> The selectors of every component added so far */
    public function selectors(): array
    {
        return array_keys($this->found);
    }

    /** @return array<string, array{class-string, Template}> */
    private function compile(): array
    {
        if ($this->compiled === null) {
            // Each template expands the others' tags: compiled once all of them are known, kept only if all compile
            $compiled = [];
            foreach ($this->found as $selector => [$class, $path]) {
                $this->hashes[$selector] = self::hash($class, $path);
                $compiled[$selector] = [$class, new Template($path, $this->selectors(), $class)];
            }
            $this->compiled = $compiled;
        }
        return $this->compiled;
    }

    /**
     * @internal Every mistake in the components and their templates that Problems finds, with its file: in dev,
     * for the terminal and the editor, before a page or a click runs into it.
     *
     * @return list<array{file: string, line: int, message: string, severity: 'error'|'warning'}>
     */
    public function check(): array
    {
        $classes = array_map(static fn (array $found) => $found[0], $this->found);
        $problems = [];
        foreach ($this->found as [$class, $path]) {
            foreach (Problems::inTemplate((string) @file_get_contents($path), $class, $classes) as $problem) {
                $problems[] = ['file' => $path] + $problem;
            }
            foreach (Problems::inComponent($class) as $problem) {
                $problems[] = ['file' => (string) (new ReflectionClass($class))->getFileName()] + $problem;
            }
        }
        return $problems;
    }

    /**
     * @internal In dev: compiles the templates whose file or class imports changed since again. One that no longer compiles keeps
     * its last version and goes to $report with its error, one that compiles goes there with null. Whether any
     * template changed.
     *
     * @param Closure(string, ?Throwable): void $report
     */
    public function reload(Closure $report): bool
    {
        $changed = false;
        foreach ($this->found as $selector => [$class, $path]) {
            // Content, not the time: an editor that saves without a change changes nothing
            $hash = is_file($path) ? self::hash($class, $path) : '';
            if ($hash === ($this->hashes[$selector] ??= $hash)) {
                continue;
            }
            $this->hashes[$selector] = $hash;
            // Not compiled yet, or not at all after a mistake: the next page compiles what is there then
            if ($this->compiled === null) {
                $report($path, null);
                continue;
            }
            try {
                $this->compiled[$selector] = [$class, new Template($path, $this->selectors(), $class)];
                $changed = true;
                $report($path, null);
            } catch (Throwable $e) {
                $report($path, $e);
            }
        }
        return $changed;
    }

    /** @param class-string $class */
    private static function hash(string $class, string $path): string
    {
        return md5((string) @file_get_contents($path) . Template::header($class));
    }

    /**
     * @internal Every class with #[Component] below a project's autoload and autoload-dev directories (PSR-4 and
     * classmap), not only those an app lists: for the editor and live-check, which run no app. A component that
     * cannot be added (no template, a taken selector) is left out; the app reports it at boot.
     */
    public static function scan(string $root): self
    {
        $components = new self();
        foreach (self::classes($root) as $class) {
            try {
                $components->add($class);
            } catch (Throwable) {
            }
        }
        return $components;
    }

    /** @return list<class-string> Classes with #[Component] in the project's autoloaded directories */
    private static function classes(string $root): array
    {
        $composer = json_decode((string) @file_get_contents("$root/composer.json"), true);
        $directories = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = is_array($composer) && is_array($composer[$section] ?? null) ? $composer[$section] : [];
            foreach (['psr-4', 'classmap'] as $kind) {
                // psr-4 maps a prefix to one directory or several, classmap lists them
                foreach ((array) ($autoload[$kind] ?? []) as $paths) {
                    foreach ((array) $paths as $path) {
                        if (is_string($path)) {
                            $directories[] = "$root/" . trim($path, '/');
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
                // Loaded only with the attribute in front of a declaration: another class may need what only an app has
                $class = preg_match('/^\s*#\[Component\b/m', $source) === 1 ? self::declaredClass($source) : null;
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
