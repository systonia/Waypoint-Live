<?php

namespace Waypoint\Live;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use Waypoint\Container;
use Waypoint\Http\{Request, Response};
use Waypoint\Plugin\{ClientAsset, PluginBase, ResponseHook, ViewHelper};
use Waypoint\Routing\PropertyInjector;

/**
 * Register with `$app->plugin(new LivePlugin([Counter::class, Search::class]))`: classes with a #[Component]
 * attribute, and only these can be created; the browser names them by selector. Adds
 * `$this->live('app-counter', $inputs)` and `<app-counter count="3"></app-counter>` for views, the WebSocket route `/_live` (LiveController) and the client
 * script. Runs under Tenma only.
 */
final class LivePlugin extends PluginBase implements ViewHelper, ClientAsset, ResponseHook
{
    private Container $container;

    /** @var array<string, array{class-string, Template}> by selector */
    private array $components = [];

    /** @param list<class-string> $components */
    public function __construct(array $components)
    {
        $templates = [];
        foreach ($components as $class) {
            $reflection = new ReflectionClass($class);
            $attribute = ($reflection->getAttributes(Component::class)[0] ?? null)?->newInstance()
                ?? throw new InvalidArgumentException("$class has no #[Component] attribute");
            // A custom element name: it becomes the tag around the component in the page
            if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $attribute->selector) !== 1 || isset($templates[$attribute->selector])) {
                throw new InvalidArgumentException("$class: selector '{$attribute->selector}' must be unique, lowercase and contain a dash");
            }
            $templates[$attribute->selector] = [$class, dirname((string) $reflection->getFileName()) . '/' . $attribute->template];
        }
        // Compiled once every selector is known: each template expands the others' tags
        foreach ($templates as $selector => [$class, $path]) {
            $this->components[$selector] = [$class, new Template($path, array_keys($templates))];
        }
    }

    public function name(): string
    {
        return 'live';
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
        $container->set($this);
    }

    public function classes(): array
    {
        return [LiveController::class];
    }

    public function hooks(): array
    {
        return [$this];
    }

    public function helpers(): array
    {
        return ['live' => $this->renderInPage(...)];
    }

    public function assets(): array
    {
        return ['live.js' => __DIR__ . '/../assets/live.js'];
    }

    /**
     * A listed component with its #[Inject] properties and its inputs set; anything else is refused.
     *
     * @param array<array-key, mixed> $inputs
     * @return array{object, Template}
     */
    public function create(string $selector, array $inputs, Scope $services): array
    {
        [$class, $template] = $this->components[$selector] ?? throw new InvalidArgumentException("'$selector' is not a live component of this app");
        $component = new $class();
        // ponytail: services the component injects must be reachable from a controller or need no constructor arguments
        (new PropertyInjector($services))->injectReflected($component);
        $this->setInputs($component, $selector, $inputs);
        return [$component, $template];
    }

    /**
     * Sets #[Input] properties; any other name is refused.
     *
     * @param array<array-key, mixed> $inputs
     */
    public function setInputs(object $component, string $selector, array $inputs): void
    {
        foreach ($inputs as $name => $value) {
            if (!$this->isInput($component::class, (string) $name)) {
                throw new InvalidArgumentException("'$name' is not an #[Input] of '$selector'");
            }
            $component->{$name} = $value;
        }
    }

    /** @param class-string $class */
    private function isInput(string $class, string $name): bool
    {
        $property = property_exists($class, $name) ? new ReflectionProperty($class, $name) : null;
        return $property !== null && $property->isPublic() && !$property->isStatic() && $property->getAttributes(Input::class) !== [];
    }

    /**
     * Expands the components' tags in a rendered HTML page. Attributes are strings in double quotes: an #[Input]
     * becomes an input, any other (class, id, ...) stays on the tag. An attribute without a value, e.g. data-live
     * on a rendered component, leaves the tag alone.
     */
    public function after(array $plan, Request $req, Response $res): void
    {
        if (!str_starts_with((string) $res->getHeader('Content-Type'), 'text/html') || $this->components === []) {
            return;
        }
        $selectors = implode('|', array_map(static fn (string $s) => preg_quote($s, '/'), array_keys($this->components)));
        $res->mapBody(fn (string $body): string => (string) preg_replace_callback(
            '/<(' . $selectors . ')((?:\s+[A-Za-z_][\w.:-]*="[^"]*")*)\s*(?:\/>|>\s*<\/\1>)/',
            function (array $m): string {
                preg_match_all('/\s+([A-Za-z_][\w.:-]*)="([^"]*)"/', $m[2], $attributes, PREG_SET_ORDER);
                $inputs = [];
                $rest = '';
                foreach ($attributes as [$attribute, $name, $value]) {
                    if ($this->isInput($this->components[$m[1]][0], $name)) {
                        $inputs[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
                    } else {
                        $rest .= $attribute;
                    }
                }
                return Circuit::page($this, $m[1], $inputs, $rest);
            },
            $body,
        ));
    }

    /** A new circuit's services: its own #[Scoped] ones, the app's for the rest. */
    public function scope(): Scope
    {
        return new Scope($this->container);
    }

    /** @param array<string, mixed> $inputs */
    private function renderInPage(string $selector, array $inputs = []): string
    {
        return Circuit::page($this, $selector, $inputs);
    }
}
