<?php

namespace Waypoint\Live;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionObject;
use Waypoint\Container;
use Waypoint\Plugin\{ClientAsset, PluginBase, ViewHelper};
use Waypoint\Routing\PropertyInjector;

/**
 * Register with `$app->plugin(new LivePlugin([Counter::class, Search::class]))`: classes with a #[Component]
 * attribute, and only these can be created; the browser names them by selector. Adds
 * `$this->live('app-counter', $inputs)` for views, the WebSocket route `/_live` (LiveController) and the client
 * script. Runs under Tenma only.
 */
final class LivePlugin extends PluginBase implements ViewHelper, ClientAsset
{
    private Container $container;

    /** @var array<string, array{class-string, Template}> by selector */
    private array $components = [];

    /** @param list<class-string> $components */
    public function __construct(array $components)
    {
        foreach ($components as $class) {
            $reflection = new ReflectionClass($class);
            $attribute = ($reflection->getAttributes(Component::class)[0] ?? null)?->newInstance()
                ?? throw new InvalidArgumentException("$class has no #[Component] attribute");
            // A custom element name: it becomes the tag around the component in the page
            if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $attribute->selector) !== 1 || isset($this->components[$attribute->selector])) {
                throw new InvalidArgumentException("$class: selector '{$attribute->selector}' must be unique, lowercase and contain a dash");
            }
            $template = new Template(dirname((string) $reflection->getFileName()) . '/' . $attribute->template);
            $this->components[$attribute->selector] = [$class, $template];
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
    public function create(string $selector, array $inputs): array
    {
        [$class, $template] = $this->components[$selector] ?? throw new InvalidArgumentException("'$selector' is not a live component of this app");
        $component = new $class();
        // ponytail: services the component injects must be reachable from a controller or need no constructor arguments
        (new PropertyInjector($this->container))->injectReflected($component);
        $reflection = new ReflectionObject($component);
        foreach ($inputs as $name => $value) {
            $property = $reflection->hasProperty((string) $name) ? $reflection->getProperty((string) $name) : null;
            if ($property === null || !$property->isPublic() || $property->isStatic() || $property->getAttributes(Input::class) === []) {
                throw new InvalidArgumentException("'$name' is not an #[Input] of '$selector'");
            }
            $property->setValue($component, $value);
        }
        return [$component, $template];
    }

    /** @param array<string, mixed> $inputs */
    private function renderInPage(string $selector, array $inputs = []): string
    {
        [$component, $template] = $this->create($selector, $inputs);
        return sprintf(
            '<%1$s data-live data-inputs="%2$s">%3$s</%1$s>',
            $selector,
            htmlspecialchars(json_encode((object) $inputs, JSON_THROW_ON_ERROR), ENT_QUOTES),
            $template->render($component),
        );
    }
}
