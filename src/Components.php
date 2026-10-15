<?php

namespace Waypoint\Live;

use Closure;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use Throwable;

/**
 * The app's live components: those given to LivePlugin, and those another plugin adds in its boot(), as
 * `$container->get(Components::class)->add(Login::class)`. Only these can be created; the browser names them by
 * selector. The templates are compiled on first use, once every selector is known.
 */
final class Components
{
    /** @var array<string, array{class-string, string}> selector => class, template file */
    private array $found = [];

    /** @var array<string, array{class-string, Template}>|null */
    private ?array $compiled = null;

    /** @var array<string, string> selector => hash of the template file it was compiled from */
    private array $hashes = [];

    /** @param class-string ...$classes Classes with a #[Component] attribute */
    public function add(string ...$classes): self
    {
        if ($this->compiled !== null) {
            throw new LogicException('Components are added at boot, before the first page uses them');
        }
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $attribute = ($reflection->getAttributes(Component::class)[0] ?? null)?->newInstance()
                ?? throw new InvalidArgumentException("$class has no #[Component] attribute");
            // A custom element name: it becomes the tag around the component in the page
            if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $attribute->selector) !== 1 || isset($this->found[$attribute->selector])) {
                throw new InvalidArgumentException("$class: selector '{$attribute->selector}' must be unique, lowercase and contain a dash");
            }
            $template = dirname((string) $reflection->getFileName()) . '/' . $attribute->template;
            // Missing at boot, not on the first page that would show it
            if (!is_file($template)) {
                throw new InvalidArgumentException("$class: template '{$attribute->template}' not found next to the class");
            }
            $this->found[$attribute->selector] = [$class, $template];
        }
        return $this;
    }

    /** @return array{class-string, Template}|null */
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

    /** @return list<string> */
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
                $this->hashes[$selector] = (string) md5_file($path);
                $compiled[$selector] = [$class, new Template($path, $this->selectors())];
            }
            $this->compiled = $compiled;
        }
        return $this->compiled;
    }

    /**
     * @internal In dev: compiles the templates whose file changed since again. One that no longer compiles keeps
     * its last version and goes to $report with its error, one that compiles goes there with null. Whether any
     * template changed.
     *
     * @param Closure(string, ?Throwable): void $report
     */
    public function reload(Closure $report): bool
    {
        if ($this->compiled === null) {
            return false;
        }
        $changed = false;
        foreach ($this->found as $selector => [$class, $path]) {
            // Content, not the time: an editor that saves without a change changes nothing
            $hash = is_file($path) ? (string) md5_file($path) : '';
            if ($hash === $this->hashes[$selector]) {
                continue;
            }
            $this->hashes[$selector] = $hash;
            try {
                $this->compiled[$selector] = [$class, new Template($path, $this->selectors())];
                $changed = true;
                $report($path, null);
            } catch (Throwable $e) {
                $report($path, $e);
            }
        }
        return $changed;
    }
}
